<?php
// Paid retries use native funding, the runtime queue and real preflight/seal/bridge. Offline edges only.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/staff-panel.php';staffPanel();
use Illuminate\Support\Facades\{Cache,DB,Queue};
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,CatalogueAcceptance,Capabilities,CostUnit,Disposition,DriverResult,Environment,Evidence,Money,QuoteEvidence,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,BalanceRetries,BalanceSchema,CatalogueAcceptances,OperationClaims,RegistrarRegistry,Rdap,RenewalAcceptance,RuntimeComposition,RuntimeDispatch,RuntimePorts,RuntimeSchema,RuntimeWork,StaffAlertSchema,TransferPrecheck,WriteAcceptance,WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Management,Registration,Transfer};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
RuntimeSchema::install();BalanceSchema::install();StaffAlertSchema::install();Queue::fake();
$staff=staffUser(['admin.domains.view']);
$notices=static fn()=>App\Models\Notification::where('user_id',$staff->id)->count();
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$account=Account::where('server_id',$tlds[0]->server_id)->sole();$binding=CatalogueAcceptances::binding($account);
$catalogue=CatalogueAcceptances::load($binding);
DB::transaction(static fn()=>CatalogueAcceptances::saveLocked(new CatalogueAcceptance($binding,$catalogue->revision,$catalogue->endings,'https://resellertest.enom.com/interface.asp',25)));
$state=(object)['query'=>null,'price'=>'99.00','funds'=>'1.00','available'=>true,'priced'=>true,'readers'=>[],'sends'=>0,'rdap'=>0,'cap'=>false,'prewireCap'=>false,'afterwireCap'=>false];
$registry=new RegistrarRegistry;
$reader=static function(AccountBinding $b,array $c)use($clock,$state):FakeRegistrar {
        $quote=new QuoteEvidence($state->query,new Money($state->price,'USD'),CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+120 seconds'));
        $reader=new FakeRegistrar($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,
            new Evidence(quote:$state->priced?$quote:null,balance:$state->funds===null?null:new Money($state->funds,'USD'),available:$state->available,premium:false)));
        $reader->beforeRead=static function($method)use($state):void {if($method==='balance'&&$state->cap){Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::refuse();}};
        $state->readers[]=$reader;return $reader;
    };
$registry->register('enom',['token'],new class implements AccountVerifier {public function verify(Environment $e,#[SensitiveParameter] array $c):string{return $c['token'];}},$reader,registrationPreflightFactory:$reader);
$freshBindings=new AccountBindings($registry);
app()->instance(AccountBindings::class,$freshBindings);app()->instance(OperationClaims::class,new OperationClaims($freshBindings,$clock));
$offline=static fn()=>throw new LogicException('No network in balance retry tests');
$precheck=new TransferPrecheck(new Rdap(static function($url)use($state):array {
    $state->rdap++;
    return ['status'=>200,'body'=>json_encode(str_contains($url,'dns.json')?['services'=>[[['com'],['https://rdap.example.com/']]]]:
        ['objectClassName'=>'domain','ldhName'=>$state->query->name,'status'=>['active'],'events'=>[
            ['eventAction'=>'registration','eventDate'=>'2020-01-01T00:00:00Z'],['eventAction'=>'expiration','eventDate'=>'2027-01-01T00:00:00Z']]])];
}));
$registration=new Registration($binding,static function($origin,$command,$p,$timeout)use($state,$binding):string {
    same(0,DB::transactionLevel(),'Send outside locks');
    if($state->prewireCap){Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::refuse();}
    if(Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::purchaseUnconsumed($binding->fingerprint)){
        $day=Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::prepareSend($binding,true);
        Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::authorizeSend($binding,true,$day);
    }
    $state->sends++;
    if($state->afterwireCap){Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::refuse();}
    return '<interface-response><Command>Purchase</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>200</RRPCode><OrderID>1234</OrderID><ExpirationDate>2027-10-18</ExpirationDate></interface-response>';
});
$transfer=new Transfer($binding,static function($origin,$command,$p,$timeout)use($state):string {
    same(0,DB::transactionLevel(),'Transfer outside locks');$state->sends++;
    return '<interface-response><Command>TP_CreateOrder</Command><ErrCount>0</ErrCount><Done>true</Done><transferorder><transferorderid>7001</transferorderid><statusid>5</statusid></transferorder></interface-response>';
});
App\Models\Server::findOrFail($binding->serverId)->settings()->updateOrCreate(['key'=>Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::KEY],['encrypted'=>true,'type'=>'string','value'=>json_encode(['fingerprint'=>$binding->fingerprint,...Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::protocol()],JSON_THROW_ON_ERROR)]);
$features=new WriteFeatures(registration:true,transfer:true);$accepted=new WriteAcceptance($binding,true,true,25);
$ports=new RuntimePorts;
$ports->register($binding->fingerprint,static fn()=>RuntimeComposition::make($binding,$freshBindings,$clock,$features,$accepted,
    new RenewalAcceptance($binding,true,true,25),$precheck,$registration,$transfer,$offline,new Management($binding,$offline)));
$worker=new RuntimeWork($clock,$ports);$dispatch=new RuntimeDispatch($clock);app()->instance(RuntimeDispatch::class,$dispatch);
$advance=static function(int $seconds)use($clock):void {
    $clock->instant=$clock->now()->modify('+'.$seconds.' seconds');Cache::flush();
    Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
};
$due=static fn($op)=>(int)DB::table('domains_runtime_operations')->where('operation_id',$op->id)->value('due_at');
$readCount=static fn()=>array_sum(array_map(static fn($r)=>count($r->calls),$state->readers));
$failures=[];
foreach(['funded','short','paused','two_ticks','transfer','read_cap','sealed_cap','sent_cap','price_drop'] as $case){
    $advance(61);$state->funds='1.00';$state->price='99.00';$noticeBefore=$notices();
    config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true,'domains.enom_writes.transfer'=>true]);
    $op=$new(placeholderComFixtureName('balance-'.str_replace('_','-',$case).'-placeholder.com'),true,true,$case==='transfer'?'transfer':'register');$state->query=OperationClaims::query($op,$op->domain);
    config(['domains.enom_writes.registration'=>false,'domains.enom_writes.transfer'=>false]);
    $worker->operation($op->id);$held=$op->fresh();
    same(['held','registrar_balance_insufficient','absent'],[$held->state,$held->safe_code,$held->attempt['seal']],$case.': initial shortfall is proven unsent');
    same($noticeBefore+1,$notices(),'First shortfall alerts staff once');
    $claim=$held->attempt['claim_key'];$funding=$held->funding;$money=$nativeMoney();$before=$state->sends;
    try {
        if($case==='price_drop'){
            $state->funds='20.00';$state->price='9.00';
            foreach([900,1800,3600,7200,14400,21600] as $wait){
                $advance($wait);$worker->operation($op->id);if($state->sends>$before){break;}
            }
            same($before+1,$state->sends,'A lower fresh price becomes affordable without a staff click');
            same('9.00',$op->fresh()->attempt['authorized_cost']['amount'],'Send uses the decreased fresh price');
            $worker->operation($op->id);same($before+1,$state->sends,'Price recovery cannot send twice');
        }elseif($case==='short'){
            $advance(899);$worker->operation($op->id);same($before,$state->sends,'Early retry sends nothing');
            $advance(1);$reads=$readCount();$worker->operation($op->id);
            same($clock->now()->getTimestamp()+1800,$due($op),'Still short uses the next backoff');
            same(['balance'],end($state->readers)->calls,'Still short stops after one fresh balance read');
            same($reads+1,$readCount(),'Shortfall uses only one read');same($before,$state->sends,'Still short sends nothing');
            same($funding,$op->fresh()->funding,'Shortfall retains original funding');same($money,$nativeMoney(),'Retry moves no native money');
            $state->funds='0.00'; // An empty account still needs bounded quote refresh, not an eternal old price.
            foreach([[1800,3600],[3600,7200],[7200,14400],[14400,21600],[21600,21600]] as [$wait,$next]){
                $advance($wait);$reads=$readCount();$worker->operation($op->id);
                same($clock->now()->getTimestamp()+$next,$due($op),'Backoff grows and stays capped at six hours');
                same($reads+($wait>=14400?3:1),$readCount(),'Persistent shortfalls reprice only once backoff reaches the cap');same($before,$state->sends,'Backoff cannot send');
            }
            $advance(21600);$state->funds='100.00';$worker->operation($op->id);$worker->operation($op->id);
            same($before+1,$state->sends,'Funds after extended backoff still send exactly once');
            same($claim,$op->fresh()->attempt['claim_key'],'Extended backoff retains original claim');
        }else{
            $advance(900);$state->funds='100.00';
            if($case==='paused'){
                DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);$reads=$readCount();$next=$due($op);
                $worker->tickOperations();$worker->operation($op->id);
                same($reads,$readCount(),'Paused retry performs no reads');same($before,$state->sends,'Paused retry sends nothing');same($next,$due($op),'Pause does not consume the retry');
                DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>true]);
            }
            if($case==='two_ticks'){
                Queue::fake();$worker->tickOperations();$advance(61);$worker->tickOperations();
                expect(Queue::pushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class)->contains(fn($job)=>$job->operationId===$op->id),'Due held order enters the existing queue');
            }
            if(in_array($case,['read_cap','sealed_cap'],true)){
                if($case==='sealed_cap'){config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true]);}
                $state->cap=$case==='read_cap';$state->prewireCap=$case==='sealed_cap';
                $worker->operation($op->id);
                same(['held','write_rate_limited','absent'],[$op->fresh()->state,$op->fresh()->safe_code,$op->fresh()->attempt['seal']],'Our cap defers a proven-unsent retry');
                same($before,$state->sends,'Cap cannot send');same($claim,$op->fresh()->attempt['claim_key'],'Cap preserves original claim');
                same($clock->now()->getTimestamp()+1800,$due($op),'Cap schedules the next retry');
                $state->cap=false;$state->prewireCap=false;$advance(1800);
            }
            if($case==='sent_cap'){
                config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true]);
                $state->afterwireCap=true;
            }
            $worker->operation($op->id);$worker->operation($op->id);
            same($before+1,$state->sends,$case.': funds send once without Release hold');
            if($case==='sent_cap'){
                same('sealed',$op->fresh()->attempt['seal'],'A refusal after sending retains the seal');
                expect(!Paymenter\Extensions\Servers\Domains\Services\BalanceRetries::candidate($op->fresh()),'Possibly sent work cannot retry for balance');
            }
            same($claim,$op->fresh()->attempt['claim_key'],'Automatic retry retains original claim');
            same($funding['claim'],$op->fresh()->funding['claim'],'Automatic retry retains funding proof');
            expect(!isset($op->fresh()->policy['staff_actions']),'Automatic retry never invents staff history');
        }
    }catch(Throwable $e){$failures[]=$case.': '.$e->getMessage();}
    same($noticeBefore+1,$notices(),'Repeated retries do not spam the hold alert');
    $state->cap=false;$state->prewireCap=false;$state->afterwireCap=false;config(['domains.enom_writes.registration'=>false]);
}
// Stored pre-claim balance checks need no staff release; the normal claim creates their first identity.
foreach([['usd_balance_required',false],['enom_test_transfer_usd_balance_required',false],['usd_balance_required',true]] as [$oldCode,$claimed]){
    $advance(61);config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true,'domains.enom_writes.transfer'=>true]);
    $op=$new(placeholderComFixtureName('old-balance-'.($oldCode==='usd_balance_required'?'register':'transfer').'-'.($claimed?'claimed':'unclaimed').'-placeholder.com'),true,true,$oldCode==='usd_balance_required'?'register':'transfer');
    config(['domains.enom_writes.registration'=>false,'domains.enom_writes.transfer'=>false]);
    $original=$claimed?$claims->claim($op->id)[0]:null;
    if($original){$claims->finish($original,null,$oldCode);$op=$op->fresh();}
    else{$op->update(['state'=>'held','safe_code'=>$oldCode]);}$state->query=OperationClaims::query($op,$op->domain);$state->funds='100.00';$before=$state->sends;$first=count($state->readers);
    try {
    $dispatch->enqueue($op->id);$advance(900);$worker->operation($op->id);$worker->operation($op->id);
    same($before+1,$state->sends,$oldCode.': funded stored hold sends once without a click');
    same('balance',$state->readers[$first]->calls[0]??null,'Stored retry reads balance first');
    expect(isset($op->fresh()->policy['balance_retry']),'Stored hold uses typed retry authority');
    expect(!isset($op->fresh()->policy['staff_actions']),'Stored retry invents no staff event');
    if($original){same($original->attempt['claim_key'],$op->fresh()->attempt['claim_key'],'Stored claimed retry retains its original claim');}
    }catch(Throwable $e){$failures[]=$oldCode.': '.$e->getMessage();}
}
$advance(61);config(['domains.enom_writes.registration'=>true]);$op=$new(placeholderComFixtureName('old-balance-low-placeholder.com'));
config(['domains.enom_writes.registration'=>false]);$op->update(['state'=>'held','safe_code'=>'usd_balance_required']);
$state->query=OperationClaims::query($op,$op->domain);$state->funds='1.00';$before=$state->sends;
try {
$dispatch->enqueue($op->id);$advance(900);$worker->operation($op->id);
same(['held','registrar_balance_insufficient'],[$op->fresh()->state,$op->fresh()->safe_code],'Stored check discovers an actual shortfall');
same($clock->now()->getTimestamp()+1800,$due($op),'Stored shortfall schedules the next backoff');same($before,$state->sends,'Stored shortfall cannot send');
}catch(Throwable $e){$failures[]='stored shortfall: '.$e->getMessage();}
// A failed read or missing price retries the same way, with no click and no send, then runs the full fresh preflight.
$retried=static fn($op)=>$op->fresh()->policy['read_retries']??0;
foreach(['read'=>['available',null,true,'registrar_read_failed'],'price'=>['priced',false,true,'registrar_price_missing'],'balance_read'=>['funds',null,'100.00','registrar_read_failed']] as $case=>[$edge,$broken,$healthy,$code]){
    $advance(61);config(['domains.enom_writes.registration'=>true]);$op=$new(placeholderComFixtureName('retry-'.str_replace('_','-',$case).'-placeholder.com'));
    config(['domains.enom_writes.registration'=>false]);$state->query=OperationClaims::query($op,$op->domain);$state->funds='100.00';$state->price='9.00';$state->$edge=$broken;$noticeBefore=$notices();$before=$state->sends;
    try {
    $worker->operation($op->id);$held=$op->fresh();
    same(['held',$code,'absent',1],[$held->state,$held->safe_code,$held->attempt['seal'],$retried($op)],$case.': refusal is proven unsent');
    same($clock->now()->getTimestamp()+900,$due($op),$case.': first retry uses the balance backoff');
    $advance(900);$worker->operation($op->id);
    same(['held',$code,2],[$op->fresh()->state,$op->fresh()->safe_code,$retried($op)],$case.': still failing keeps its real cause');
    same($clock->now()->getTimestamp()+1800,$due($op),$case.': next retry backs off');
    $state->$edge=$healthy;$advance(1800);$worker->operation($op->id);$worker->operation($op->id);
    same($before+1,$state->sends,$case.': a healed read sends once without a click');
    same([$held->attempt['claim_key'],$held->funding['claim']],[$op->fresh()->attempt['claim_key'],$op->fresh()->funding['claim']],$case.': retry keeps the original claim audit');
    expect(!isset($op->fresh()->policy['staff_actions']),$case.': retry invents no staff history');
    same($noticeBefore,$notices(),$case.': a read that recovers pages nobody');
    }catch(Throwable $e){$failures[]=$case.': '.$e->getMessage();}
    $state->available=true;$state->priced=true;$state->funds='100.00';
}
// Retries stop at the limit, and staff are told once.
$advance(61);config(['domains.enom_writes.registration'=>true]);$op=$new(placeholderComFixtureName('retry-limit-placeholder.com'));
config(['domains.enom_writes.registration'=>false]);$state->query=OperationClaims::query($op,$op->domain);$state->available=null;$noticeBefore=$notices();$before=$state->sends;
try {
$worker->operation($op->id);
foreach(range(2,BalanceRetries::READ_LIMIT) as $count){
    same(0,$notices()-$noticeBefore,'No page while a retry is coming');
    $advance(21600);$worker->operation($op->id);same($count,$retried($op),'Each failed read counts');
}
same(['held','registrar_read_failed'],[$op->fresh()->state,$op->fresh()->safe_code],'Retries stop on the real problem');
expect(!BalanceRetries::pending($op->fresh()),'Exhausted reads are no longer pending');same(1,$notices()-$noticeBefore,'Staff are told once');
$reads=$readCount();$advance(21600);$worker->tickOperations();$worker->operation($op->id);
same([$reads,$before],[$readCount(),$state->sends],'Exhausted reads make no more calls');
}catch(Throwable $e){$failures[]='read limit: '.$e->getMessage();}
$state->available=true;
// An old catch-all hold proves nothing. One fresh look, with no click, finds the real answer, and only then may this code mean what it says.
$legacy=static function(string $name)use($new,$worker,$state,$advance):Paymenter\Extensions\Servers\Domains\Models\Operation {
    $advance(61);config(['domains.enom_writes.registration'=>true]);$op=$new(placeholderComFixtureName($name));config(['domains.enom_writes.registration'=>false]);
    $state->query=OperationClaims::query($op,$op->domain);$state->available=null;$state->funds='100.00';$state->price='9.00';$worker->operation($op->id);$state->available=true;
    $old=$op->fresh();$policy=$old->policy;unset($policy['balance_retry'],$policy['read_retries']);$old->policy=$policy;
    DB::table('domains_operations')->where('id',$op->id)->update(['safe_code'=>'write_acceptance_required','policy'=>$old->getAttributes()['policy']]);DB::table('domains_runtime_operations')->where('operation_id',$op->id)->delete();
    return $op->fresh();
};
foreach(['sends'=>[true,'pending','registration_evidence_required',1],'taken'=>[false,'held','name_unavailable',0],'read'=>[null,'held','registrar_read_failed',0]] as $case=>[$available,$end,$code,$sent]){
    $op=$legacy('legacy-'.$case.'-placeholder.com');$claim=$op->attempt['claim_key'];$before=$state->sends;Illuminate\Support\Facades\Auth::setUser($staff);
    try {
    $notice=Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::notice($op);
    same(['Not sent: checking this order again.',null,null,null],[$notice['title'],$notice['body'],$notice['action']??null,$notice['link']??null],$case.': waiting words claim no cause and offer no click');
    $worker->tickOperations();$advance(61);$worker->tickOperations();$due=DB::table('domains_runtime_operations')->where('operation_id',$op->id)->value('due_at');expect($due!==null,$case.': the runtime scan finds the old row');
    $state->available=$available;$advance(900);$worker->operation($op->id);$worker->operation($op->id);$state->available=true;$after=$op->fresh();
    same([$end,$code,$sent,$claim],[$after->state,$after->safe_code,$state->sends-$before,$after->attempt['claim_key']],$case.': one look replaces the old code with the real answer');
    }catch(Throwable $e){$failures[]='legacy '.$case.': '.$e->getMessage();}
}
if($failures){throw new RuntimeException(implode("\n",$failures));}
// After that look this code comes only from a real acceptance check, so a new hold keeps its meaning and waits for its fix.
$after=$op->fresh();DB::table('domains_operations')->where('id',$op->id)->update(['safe_code'=>'write_acceptance_required']);$after=$op->fresh();
expect(!BalanceRetries::legacyHold($after)&&!BalanceRetries::pending($after),'A second look is never scheduled');
same('Not sent: Registrations off for this account.',Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::notice($after)['title'],'A hold from current code keeps its meaning');
echo "Automatic balance retry: funding, backoff, pause and duplicate ticks passed.\n";
$nativeSuccessful=true;
