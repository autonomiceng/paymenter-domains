<?php
// Fresh ZIP composition and native paid work; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require __DIR__.'/commerce.php';require __DIR__.'/native-commerce.php';
use Illuminate\Support\Facades\{Cache,DB,Queue};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier,Clock};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,Capabilities,CostUnit,Disposition,DriverResult,Environment,Evidence,Money,QuoteEvidence,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AcquisitionPort,AcquisitionPorts,CartAdmission,CatalogueAcceptances,CheckoutEligibility,InvoiceBinding,NativeCatalogue,OperationClaims,PriceCatalogue,Rdap,Refunds,RegistrarRegistry,RegistrationProfiles,RenewalAcceptance,RuntimeComposition,RuntimePorts,RuntimeWork,SellerPolicies,TransferInputs,TransferPrecheck,WriteAcceptance,WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Registration,Transfer,Management};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
expect(!is_link(base_path('extensions/Servers/Domains')),'Acquisition tests use extracted ZIP bytes');
foreach([AcquisitionPort::class,AcquisitionPorts::class,RuntimeComposition::class] as $type){
    expect(str_starts_with((new ReflectionClass($type))->getFileName(),base_path('extensions/Servers/Domains/')),'Composition is installed runtime');
}
extract(commerceFixture(true,static fn()=>null));
$clock=new class($now) implements Clock {
    public function __construct(public DateTimeImmutable $at){}
    public function now(): DateTimeImmutable {return $this->at;}
};
app()->instance(Clock::class,$clock);
if(getenv('PACKAGE_PHASE')==='enom-exchange'){require __DIR__.'/runtime-enom-exchange.php';return;}
foreach([AccountBindings::class=>$bindings,NativeCatalogue::class=>$native,PriceCatalogue::class=>$prices,SellerPolicies::class=>$sellers] as $type=>$instance){app()->instance($type,$instance);}
$offline=static fn()=>throw new LogicException('No exchange supplied to this fixture');
$checkoutCheck=new TransferPrecheck(new Rdap($offline));
$admission=new CartAdmission($native,$sellers,new CheckoutEligibility($bindings,$clock,$checkoutCheck),$clock);
app()->instance(CartAdmission::class,$admission);
app()->instance(InvoiceBinding::class,new InvoiceBinding($admission,$native,$prices,$clock));
(new Paymenter\Extensions\Servers\Domains\Domains)->boot();
Queue::fake();
$user=commerceUser();$user->update(['email'=>'acquisition-owner@example.com']);
foreach(['address'=>'1 Synthetic Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value){$user->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]);}
App\Models\Server::find($tlds[0]->server_id)->settings()->create(['key'=>'domains_transfer_endings','value'=>'com']);
$account=Account::where('server_id',$tlds[0]->server_id)->sole();$binding=CatalogueAcceptances::binding($account);
$other=CatalogueAcceptances::binding(Account::where('server_id',$tlds[1]->server_id)->sole());
$state=(object)['query'=>null,'reads'=>0,'premium'=>false,'writes'=>[],'rdap'=>0];
$registry=new RegistrarRegistry;
$verifier=new class implements AccountVerifier {public function verify(Environment $e,array $c):string{return $c['token'];}};
$registry->register('enom',['token'],$verifier,static function($b)use($clock,$state){
    same(0,DB::transactionLevel(),'Composition reader runs outside financial locks');$state->reads++;
    $quote=new QuoteEvidence($state->query,new Money('99.00','USD'),CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+120 seconds'));
    return new FakeRegistrar($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,
        new Evidence(quote:$quote,balance:new Money('100.00','USD'),available:true,premium:$state->premium)));
});
$freshBindings=new AccountBindings($registry);
$precheck=new TransferPrecheck(new Rdap(static function($url)use($state){
    same(0,DB::transactionLevel(),'Transfer RDAP runs outside financial locks');$state->rdap++;
    return ['status'=>200,'body'=>json_encode(str_contains($url,'dns.json')?['services'=>[[['com'],['https://rdap.example.com/']]]]:
        ['objectClassName'=>'domain','ldhName'=>$state->query->name,'status'=>['active'],'events'=>[
            ['eventAction'=>'registration','eventDate'=>'2020-01-01T00:00:00Z'],['eventAction'=>'expiration','eventDate'=>'2027-01-01T00:00:00Z']]])];
}));
$register=new Registration($binding,static function($origin,$command,$p,$timeout)use($state){
    same('Purchase',$command,'Registration has its own fixed protocol spy');same(0,DB::transactionLevel(),'Purchase outside locks');
    expect($timeout>0&&$timeout<=25000,'Bounded purchase deadline');$state->writes[]=['register',$p['SLD'].'.'.$p['TLD']];
    return '<interface-response><Command>Purchase</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>200</RRPCode><OrderID>1234</OrderID><ExpirationDate>2027-10-18</ExpirationDate></interface-response>';
});
$transfer=new Transfer($binding,static function($origin,$command,$p,$timeout)use($state){
    same('TP_CreateOrder',$command,'Transfer has its own fixed protocol spy');same(0,DB::transactionLevel(),'Transfer outside locks');
    expect($timeout>0&&$timeout<=25000,'Bounded transfer deadline');$state->writes[]=['transfer',$p['SLD1'].'.'.$p['TLD1']];
    return '<interface-response><Command>TP_CreateOrder</Command><ErrCount>0</ErrCount><Done>true</Done><transferorder><transferorderid>7001</transferorderid><statusid>5</statusid></transferorder></interface-response>';
});
$features=new WriteFeatures(registration:true,transfer:true);$accepted=new WriteAcceptance($binding,true,true,25);
$renewAccepted=new RenewalAcceptance($binding,true,true,25);$management=new Management($binding,$offline);
$factory=static fn(?WriteFeatures $flags=null)=>RuntimeComposition::make($binding,$freshBindings,$clock,$flags??$features,$accepted,$renewAccepted,$precheck,$register,$transfer,$offline,$management);
$before=[$state->reads,$state->rdap,$state->writes,Account::orderBy('id')->get()->map->getRawOriginal()->all()];
$composition=$factory();
same(array_keys(RuntimePorts::TYPES),array_keys($composition),'Factory supplies exactly seven existing runtime roles');
same($before,[$state->reads,$state->rdap,$state->writes,Account::orderBy('id')->get()->map->getRawOriginal()->all()],'Factory has no provider or processing side effects');
$refused=static function(callable $call,string $message):void{
    try{$call();}catch(DomainException|InvalidArgumentException $error){same($message,$error->getMessage(),'Specific composition refusal');return;}
    throw new RuntimeException('Expected composition refusal: '.$message);
};
$registrationPort=AcquisitionPort::registration($binding,$freshBindings,$clock,$features,$accepted,$register);
$transferPort=AcquisitionPort::transfer($binding,$freshBindings,$clock,$features,$accepted,$transfer,$precheck);
foreach([
    AcquisitionPort::transfer($binding,$freshBindings,$clock,new WriteFeatures(true,true),$accepted,$transfer,$precheck),
    AcquisitionPort::transfer($binding,$freshBindings,$clock,$features,new WriteAcceptance($binding,true,true,25),$transfer,$precheck),
    AcquisitionPort::transfer($binding,$freshBindings,$clock,$features,$accepted,$transfer,$precheck,new RegistrationProfiles($binding,new OperationClaims($bindings,$clock),$clock,$offline)),
] as $differentPolicy){$refused(fn()=>new AcquisitionPorts($binding,$registrationPort,$differentPolicy),'Acquisition policy objects differ.');}
$refused(fn()=>AcquisitionPort::registration($binding,$freshBindings,$clock,$features,$accepted,$transfer),'Registration protocol action mismatch.');
try { AcquisitionPort::transfer($binding,$freshBindings,$clock,$features,$accepted,$register,$precheck);throw new RuntimeException('Expected typed transfer refusal'); }
catch (TypeError) {}
$refused(fn()=>new AcquisitionPorts($binding,$transferPort,$transferPort),'Acquisition action mismatch.');
$refused(fn()=>new AcquisitionPorts($binding,$registrationPort,$registrationPort),'Acquisition action mismatch.');
$refused(fn()=>new AcquisitionPorts($other,$registrationPort,$transferPort),'Registrar account binding mismatch.');
$refused(fn()=>AcquisitionPort::registration(new AccountBinding('unavailable',1,Environment::Test,'synthetic'),$freshBindings,$clock,$features,$accepted,$register),'Acquisition registrar unavailable.');
$refused(fn()=>AcquisitionPort::registration($other,$freshBindings,$clock,$features,$accepted,$register),'Registration policy composition is unavailable.');
$refused(fn()=>RuntimeComposition::make($binding,$freshBindings,$clock,$features,$accepted,$renewAccepted,$precheck,$register,$transfer,$offline,new Management($other,$offline)),'Runtime management binding changed.');
// NameSilo requires one exact profile object across acceptance, both preflights and protocols.
$profiles=new RegistrationProfiles($other,new OperationClaims($bindings,$clock),$clock,$offline);
$nsAccept=new WriteAcceptance($other,true,true,25,$profiles);
$nsRegistration=new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Registration($other,$profiles,$offline);
$nsTransfer=new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Transfer($other,$profiles,$offline);
$nsSelector=new AcquisitionPorts($other,AcquisitionPort::registration($other,$bindings,$clock,$features,$nsAccept,$nsRegistration,$profiles),
    AcquisitionPort::transfer($other,$bindings,$clock,$features,$nsAccept,$nsTransfer,$precheck,$profiles));
$wrongProfiles=new RegistrationProfiles($other,new OperationClaims($bindings,$clock),$clock,$offline);
$refused(fn()=>AcquisitionPort::registration($other,$bindings,$clock,$features,$nsAccept,$nsRegistration,$wrongProfiles),'Registration policy composition is unavailable.');
$refused(fn()=>AcquisitionPort::transfer($other,$bindings,$clock,$features,$nsAccept,$nsTransfer,$precheck,$wrongProfiles),'Registration policy composition is unavailable.');
$ports=new RuntimePorts;$ports->register($binding->fingerprint,fn()=>$composition);app()->instance(RuntimePorts::class,$ports);
expect($ports->accepted($account),'Checked factory is locally complete');
same(['register'=>true,'transfer'=>true],$ports->acquisitionGates($account),'Separate action features visible');
$closed=new RuntimePorts;$closed->register($binding->fingerprint,fn()=>$factory(new WriteFeatures(transfer:true)));
same(['register'=>false,'transfer'=>true],$closed->acquisitionGates($account),'Registration can be off while transfer is on');
$closedReverse=new RuntimePorts;$closedReverse->register($binding->fingerprint,fn()=>$factory(new WriteFeatures(registration:true)));
same(['register'=>true,'transfer'=>false],$closedReverse->acquisitionGates($account),'Transfer can be off while registration is on');
$wrong=new RuntimePorts;$bad=$composition;$bad['acquisition']=$nsSelector;$wrong->register($binding->fingerprint,fn()=>$bad);
expect(!$wrong->accepted($account),'Wrong acquisition binding is incomplete even with matching management');
same(['register'=>null,'transfer'=>null],$wrong->acquisitionGates($account),'Wrong binding has no action readiness');
$old=new RuntimePorts;$legacy=$composition;$legacy['acquisition']=(new ReflectionProperty($registrationPort,'engine'))->getValue($registrationPort);
$old->register($binding->fingerprint,fn()=>$legacy);expect(!$old->accepted($account),'Legacy single-engine acquisition is refused');
app()->instance(RuntimePorts::class,$closed);$rows=array_column(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup($account,['label'=>'Active','reason'=>null]),null,'label');
same(['Off',Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::SERVER_CONFIG],[$rows['Registrations']['value'],$rows['Registrations']['cause']],'Setup checklist names a feature that is off in server config');
same('On',$rows['Transfers']['value'],'Setup checklist describes the exact per-action gates');
app()->instance(RuntimePorts::class,$ports);
$inputs=new TransferInputs($clock,new Refunds($clock));
$new=static function(string $name,string $action)use($user,$native,$tlds,$clock,$state,$inputs):Operation{
    Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
    $cart=commerceCart($user);commerceAdd($cart,$native->product($tlds[0],$action),$name);commerceCheckout($user,$cart)->assertHasNoErrors();
    $op=Domain::where('domain',$name)->sole()->operations()->sole();
    DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
    App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded']);
    if($action==='transfer'){
        $inputs->paymentAccepted($op->id);$r=Illuminate\Http\Request::create('/internal-transfer-input','POST',['transfer_code'=>'SyntheticOnly']);
        $r->setUserResolver(fn()=>$user);$inputs->submitCode($r,$op->id);
    }
    $state->query=OperationClaims::query($op,$op->domain);return $op->fresh();
};
foreach (['register'=>$closed,'transfer'=>$closedReverse] as $action=>$disabled) {
    $clock->at=$clock->at->modify('+61 seconds'); Cache::flush();
    $blocked=$new(placeholderComFixtureName('disabled-'.$action.'-placeholder.com'),$action);
    $blocked->update(['state'=>'queued']); same('queued',$blocked->fresh()->state,'Disabled execution starts with queued paid work');
    $before=[$state->reads,$state->rdap,$state->writes];
    $disabled->operation($blocked); $result=$blocked->fresh();
    same('held',$result->state,'Disabled '.$action.' action refuses execution');
    same('write_disabled',$result->safe_code,'Disabled action names its exact gate');
    same([],$result->attempt??[],'Disabled action stays unclaimed and unsealed');
    same($before,[$state->reads,$state->rdap,$state->writes],'Disabled action performs zero provider reads or writes');
}
// Corruption must be refused by the selector before an engine can claim or read.
$op=$new(placeholderComFixtureName('route-guards-placeholder.com'),'register');$attempt=$op->attempt;$readCount=$state->reads;
$refused(fn()=>$transferPort->execute($op->id),'Acquisition operation binding changed.');
foreach(['account_fingerprint'=>str_repeat('f',64),'server_id'=>$other->serverId,'registrar_key'=>'namesilo'] as $field=>$value){
    $original=$op->getRawOriginal($field);DB::table('domains_operations')->where('id',$op->id)->update([$field=>$value]);
    $refused(fn()=>$registrationPort->execute($op->id),'Acquisition operation binding changed.');
    DB::table('domains_operations')->where('id',$op->id)->update([$field=>$original]);
}
same([$attempt,$readCount,[]],[$op->fresh()->attempt,$state->reads,$state->writes],'Route refusal leaves no claim, reads or sends');
DB::table('domains_operations')->where('id',$op->id)->update(['action'=>'renew','cost'=>Illuminate\Support\Facades\Crypt::encryptString('[]')]);
$refused(fn()=>$composition['acquisition']->execute($op->id),'Acquisition action unavailable.');
$ports->operation($op->fresh());same('renewal_acceptance_required',$op->fresh()->safe_code,'Renewal reaches its independent disabled gate');
same([], $state->writes,'Renewal never reaches acquisition spies');
// Genuine paid native work alternates on one account with the same registered factory.
$worker=new RuntimeWork($clock,$ports);$expected=[];
foreach(['register','transfer','register','transfer'] as $i=>$action){
    $clock->at=$clock->at->modify('+61 seconds');Cache::flush();$op=$new(placeholderComFixtureName('alternating-'.$i.'-placeholder.com'),$action);
    $beforeReads=$state->reads;$beforeRdap=$state->rdap;
    $worker->invoice($op->invoice_id);Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,fn($job)=>$job->operationId===$op->id);
    $worker->operation($op->id);$expected[]=[$action,$op->domain->domain];
    same($expected,$state->writes,'Correct action protocol exchanges exactly once in order');
    same($action==='register'?'pending':'uncertain',$op->fresh()->state,'Real acquisition engine records its action outcome');
    same('sealed',$op->fresh()->attempt['seal'],'Actual funding and dispatch checks completed');
    same($action==='register'?1:2,$state->reads-$beforeReads,'Real action-specific fresh preflight count');
    if($action==='transfer'){expect($state->rdap-$beforeRdap>=2,'Transfer repeats fresh RDAP admission');}
    $composition['acquisition']->execute($op->id);same($expected,$state->writes,'Duplicate delivery never replays a monetary command');
}
foreach(['register','transfer'] as $action){
    $clock->at=$clock->at->modify('+61 seconds');Cache::flush();$op=$new(placeholderComFixtureName('failed-preflight-'.$action.'-placeholder.com'),$action);$state->premium=true;
    $ports->operation($op);$state->premium=false;
    same('held',$op->fresh()->state,'Failed fresh preflight holds '.$action);same('absent',$op->fresh()->attempt['seal'],'Preflight refusal precedes sealing');
    same($expected,$state->writes,'Failed preflight sends neither action');
}
same($binding->fingerprint,$account->fresh()->account_fingerprint,'Alternation preserves account identity');
echo "Installed typed acquisition: pure seven-role composition, action/binding refusals, independent renewal and paid alternating protocols passed.\n";
