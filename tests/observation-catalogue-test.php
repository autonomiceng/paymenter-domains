<?php
// Synthetic offline fair catalogue draining; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\{Price,SellRow};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client,Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport,TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueAcceptances,AccountReadBudget,CatalogueSync,DomainObservation,ObservationSchema,ObservationWork,PriceFill,RegistrarRegistry,SellTableSchema};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
extract(commerceFixture());ObservationSchema::install();$clock=new FakeClock($now);$enom=$tlds[0]->server_id;$ns=$tlds[1]->server_id;
$state=(object)['calls'=>0,'fail'=>false,'during'=>null,'quarantine'=>false];
$wire=new class($state) implements DeadlineTransport {
    public function __construct(private object $s){}
    public function send($e,$c,$r,#[SensitiveParameter] string $key):TransportResponse{throw new LogicException;}
    public function sendWithin($e,$c,$r,#[SensitiveParameter] string $key,int $timeout):TransportResponse{
        same(0,DB::transactionLevel(),'Catalogue reads outside locks');same('getPrices',$r->command,'Catalogue named request');$this->s->calls++;
        if($this->s->during){$hook=$this->s->during;$this->s->during=null;$hook();}
        if($this->s->fail){throw new RuntimeException('Synthetic failure');}
        $row=['registration'=>'8.00','renew'=>'9.00','transfer'=>'8.00'];
        return new TransportResponse(200,json_encode(['request'=>['operation'=>'getPrices'],'reply'=>['code'=>300,'detail'=>'synthetic','com'=>$row,
            'net'=>$this->s->quarantine?array_diff_key($row,['registration'=>true]):$row,'org'=>$row]]));
    }
};
$registry=new RegistrarRegistry;$verifier=new class implements Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier{public function verify(Environment $e,array $c):string{return $c['token'];}};
$registry->register('namesilo',['token'],$verifier,fn($b,$c)=>new Driver(new Client($b,$c['token'],$wire),[],$clock));
$reads=new AccountBindings($registry);$b=CatalogueAcceptances::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$ns)->sole());
$row=['register'=>[1],'renew'=>[1],'transfer'=>[1],'registry'=>true,'renewal_safe'=>true];CatalogueAcceptances::save($b,1,['com'=>$row,'net'=>$row,'org'=>$row]);
$budget=new AccountReadBudget($clock);$observer=new DomainObservation($reads,$clock,$budget);$work=new ObservationWork($observer,$clock,$budget,new CatalogueSync($reads,$prices));
$work->enroll($enom);$work->enroll($ns);$bindings->setProcessing($ns,false);$work->tick(1);same(0,$state->calls,'Broken first account bounded');
$work->tick(1);same(1,$state->calls,'Second account refreshes while paused and not current seller');
$work->tick(2);same(1,$state->calls,'Catalogue due time durable');
$state->quarantine=true;
$clock->instant=$now->modify('+600 seconds');$work->tick(2);expect(Price::where('server_id',$ns)->where('tld','net')->exists(),'Next bounded suffix drains');
same(3,Price::where('server_id',$ns)->where('tld','net')->whereNull('wholesale')->count(),'Quarantined suffix has unknown cost');
same(null,DB::table('domains_observation_queues')->where('server_id',$ns)->value('catalogue_hold'),'Persisted quarantine never holds healthy account refresh');
same(2,DB::table('domains_observation_queues')->where('server_id',$ns)->value('catalogue_cursor'),'Quarantine advances durable cursor');
$clock->instant=$now->modify('+1200 seconds');$work->tick(2);expect(Price::where('server_id',$ns)->where('tld','org')->exists(),'Tail suffix drains within cadence');
// What you sell reads NameSilo's one price list into the same table, yearly prices only.
SellTableSchema::install();DB::table(SellTableSchema::ROWS)->insert(['server_id'=>$ns,'account_fingerprint'=>$b->fingerprint,'tld'=>'net','register_cost'=>'7.0000']);$calls=$state->calls;(new PriceFill($reads,$clock))->run(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$ns)->sole());same($calls+1,$state->calls,'One NameSilo read');
same('7.0000',SellRow::where(['server_id'=>$ns,'tld'=>'net'])->sole()->register_cost,'A broken row keeps its last good price and does not stop the rest');
$sold=SellRow::where(['server_id'=>$ns,'tld'=>'com'])->sole();same(['8.0000','9.0000','8.0000','9.0000',[1]],[$sold->register_cost,$sold->renew_cost,$sold->transfer_cost,$sold->high_cost,array_keys($sold->prices)],'NameSilo costs land in the table');
$prior=DB::table('domains_prices')->where('server_id',$ns)->orderBy('id')->get()->toJson();$state->fail=true;
$clock->instant=$now->modify('+1800 seconds');$work->tick(2);same($prior,DB::table('domains_prices')->where('server_id',$ns)->orderBy('id')->get()->toJson(),'Failed refresh preserves price bytes/freshness');
$failedQueue=DB::table('domains_observation_queues')->where('server_id',$ns)->sole();
same('read_failed',$failedQueue->catalogue_hold,'Catalogue failure retains the closed read cause');
$audit=json_decode($failedQueue->catalogue_audit,true,8,JSON_THROW_ON_ERROR);$failure=end($audit);
same(['code'=>'read_failed','server_id'=>$ns,'account_fingerprint'=>$b->fingerprint,'at'=>$clock->now()->format(DATE_ATOM)],$failure,'Catalogue failure retains only safe code, bound account and time');
expect(!str_contains($failedQueue->catalogue_audit,'Synthetic failure'),'Provider exception message never retained');
$facts=Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::refreshFacts($ns);
same($failure,$facts,'Saved TLD facts expose the failure without a provider read');
DB::table('domains_observation_queues')->where('server_id',$ns)->update(['catalogue_hold'=>'catalogue_interrupted']);
same(['code'=>'catalogue_interrupted','server_id'=>$ns,'account_fingerprint'=>$b->fingerprint,'at'=>null],Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::refreshFacts($ns),'Active interrupted claim takes precedence over an older audited read failure');
DB::table('domains_observation_queues')->where('server_id',$ns)->update(['catalogue_hold'=>'read_failed']);
same('Sync failed: Registrar read failed',Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::refreshState($ns),'Existing TLD refresh fact includes the cause');
$calls=$state->calls;$clock->instant=$now->modify('+1 day');(new ObservationWork($observer,$clock,$budget,new CatalogueSync($reads,$prices)))->tick(2);same($calls,$state->calls,'Vendor failure remains held across due time and restart');
$state->fail=false;$work->resumeCatalogue($ns,'staff_catalogue_resume');
DB::table('domains_read_budgets')->where('server_id',$ns)->update(['used'=>60,'window_at'=>$clock->now()->getTimestamp()*1000000]);$work->tick(2);same($calls,$state->calls,'Local quota denial sends nothing');
same(null,DB::table('domains_observation_queues')->where('server_id',$ns)->value('catalogue_hold'),'Local admission denial never becomes vendor failure hold');
$clock->instant=$clock->now()->modify('+60 seconds');$work->tick(2);same($calls+1,$state->calls,'Local reset resumes without a second explicit authorization');
same(1,DB::table('domains_observation_queues')->where('server_id',$ns)->value('catalogue_resumes'),'Catalogue resume is bounded and audited');
// Setup failures before a claim also retain a cause and identity without provider I/O.
$secret=App\Models\Server::findOrFail($ns)->settings()->where('key','domains_credentials')->sole();$priorSecret=$secret->value;
$secret->value=json_encode(['token'=>'changed-without-verification']);$secret->save();
$work->resumeCatalogue($ns,'staff_configuration_probe');$calls=$state->calls;$work->tick(2);
same($calls,$state->calls,'Unverified settings refuse before the provider read');
same('configuration_unverified',DB::table('domains_observation_queues')->where('server_id',$ns)->value('catalogue_hold'),'Configuration failure retains its cause');
$secret->value=$priorSecret;$secret->save();
$acceptance=App\Models\Server::findOrFail($ns)->settings()->where('key',CatalogueAcceptances::KEY)->sole();$acceptance->delete();
$work->resumeCatalogue($ns,'staff_acceptance_probe');
$queueQuery=DB::table('domains_observation_queues')->where('server_id',$ns);
$history=[...array_fill(0,41,['reason'=>'staff_security_review','at'=>$clock->now()->format(DATE_ATOM)]),...json_decode((clone $queueQuery)->value('catalogue_audit'),true,8,JSON_THROW_ON_ERROR)];
$queueQuery->update(['catalogue_audit'=>json_encode($history,JSON_THROW_ON_ERROR)]);$work->tick(2);
same($history,array_slice(json_decode((clone $queueQuery)->value('catalogue_audit'),true,8,JSON_THROW_ON_ERROR),0,count($history)),'Failure diagnostics never truncate staff/security audit history');
same(null,DB::table('domains_observation_queues')->where('server_id',$ns)->value('catalogue_hold'),'Pre-claim acceptance failure cannot permanently hold an unclaimed read');
same('catalogue_acceptance_unavailable',Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::refreshFacts($ns)['code'],'Pre-claim cause remains reachable in saved facts');
same($b->fingerprint,Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::refreshFacts($ns)['account_fingerprint'],'Early failure retains the queue account');
same($calls,$state->calls,'Missing acceptance sends no provider requests');
$audit=DB::table('domains_observation_queues')->where('server_id',$ns)->value('catalogue_audit');
for($i=0;$i<3;$i++){$work->tick(2);}same($audit,DB::table('domains_observation_queues')->where('server_id',$ns)->value('catalogue_audit'),'Repeated pre-claim cause is deduplicated');
CatalogueAcceptances::save($b,0,['com'=>$row,'net'=>$row,'org'=>$row]);$work->tick(2);
same($calls+1,$state->calls,'Saving missing acceptance recovers automatically without catalogue resume');
$state->fail=true;$calls=$state->calls;
DB::table('domains_observation_queues')->where('server_id',$ns)->update(['catalogue_due'=>0,'catalogue_audit'=>'{']);$work->tick(2);
$failedQueue=DB::table('domains_observation_queues')->where('server_id',$ns)->sole();
same($calls+1,$state->calls,'Claimed failure sends exactly one bounded read despite corrupt diagnostic history');
same('read_failed',$failedQueue->catalogue_hold,'Corrupt audit cannot replace the actual claimed failure cause');
same(null,$failedQueue->catalogue_claim,'Corrupt audit cannot retain a completed failure claim');same(0,$failedQueue->catalogue_lease,'Corrupt audit cannot retain a completed failure lease');
try{$heldFacts=Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::refreshFacts($ns);}catch(JsonException){$heldFacts=null;}
same(['code'=>'read_failed','server_id'=>$ns,'account_fingerprint'=>$b->fingerprint,'at'=>null],$heldFacts,'Current safe hold remains visible when audit JSON is malformed');
same('{',DB::table('domains_observation_queues')->where('server_id',$ns)->value('catalogue_audit'),'Reading safe facts preserves malformed audit bytes');
DB::table('domains_observation_queues')->where('server_id',$ns)->update(['catalogue_hold'=>'synthetic-private-payload','catalogue_audit'=>null]);
expect(!str_contains(Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::refreshState($ns),'synthetic-private-payload'),'Stored hold text cannot leak into rendered facts');
same('read_exception',Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::refreshFacts($ns)['code'],'Unknown stored hold exposes only a closed fallback code');
$nativeSuccessful=true;echo "Fair bounded catalogue cursor and failure retention passed\n";
