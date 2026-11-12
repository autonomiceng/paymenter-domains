<?php
// Synthetic installed Live composition only; no provider access or activation proof.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/native-app.php';
require_once __DIR__.'/Support/FakeRegistrar.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,TransferPoller};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,AttemptEvidence,Environment,ExecutionRecord,Query,RequestBudget,RequestContext,RequestOrigin,SendState,TransferStatus};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountReadBudget,Installation,LiveActivation,LiveReservations,LocalAccountEnrollment,NameSiloFeatureSettings,RegistrarBalances,RegistrarFactories,RegistrarRegistry,RuntimePorts};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
function curl_init(): object {return (object)['options'=>[]];}
function curl_setopt_array($h,array $options): bool {$h->options=array_replace($h->options,$options);return true;}
function curl_getinfo($h,int $option): int {return 200;}
function curl_close($h): void {}
function curl_exec($h): bool {
    same(0,DB::transactionLevel(),'Installed Live wire stays outside database locks');
    $o=$h->options;$url=parse_url($o[CURLOPT_URL]);parse_str($url['query'],$p);
    $command=basename($url['path']);$GLOBALS['liveCalls'][]=$command;
    same($GLOBALS['liveExpectedKey'],$p['key']??null,'Each physical request uses the current synthetic credential');
    same('www.namesilo.com',$url['host'],'Live composition uses the fixed Live origin');
    same('/apibatch/',substr($url['path'],0,10),'Daily reads declare background routing');
    expect(in_array($command,['getAccountBalance','getDomainInfo','checkTransferStatus'],true),'This read fixture must never mutate');
    if($GLOBALS['liveFail']??false){throw new \RuntimeException('Synthetic lost reply');}
    $reply=match($command){
        'getAccountBalance'=>['balance'=>'123.45'],
        'getDomainInfo'=>['created'=>'2026-01-01','expires'=>'2027-01-01','status'=>'Active','locked'=>'Yes','private'=>'No','auto_renew'=>'No',
            'nameservers'=>[['nameserver'=>'ns1.example.net','position'=>1],['nameserver'=>'ns2.example.net','position'=>2]]],
        'checkTransferStatus'=>['date'=>'2026-11-02','status'=>'Pending at Registry','message'=>'synthetic pending'],
    };
    $body=json_encode(['request'=>['operation'=>$command],'reply'=>['code'=>300,'detail'=>'success',...$reply]],JSON_THROW_ON_ERROR);
    return ($o[CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);
}
(new Installation)->install();
$clock=new FakeClock(new \DateTimeImmutable('2026-11-02T12:00:00Z'));app()->instance(Clock::class,$clock);
config(['app.url'=>'https://billing.example.com','domains.namesilo_account.environment'=>'live',
    'domains.namesilo_sandbox_enrollment.enabled'=>false]);
$registry=new RegistrarRegistry;expect(RegistrarFactories::bootstrap($registry),'Built-in local enrollment registered');
$bindings=new AccountBindings($registry);app()->instance(AccountBindings::class,$bindings);
$server=Server::create(['name'=>'Synthetic Live runtime','type'=>'server','extension'=>'Domains','enabled'=>true]);
$identity=LocalAccountEnrollment::PREFIX.'live-runtime';
$account=Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$server->id,'registrar_key'=>'namesilo','environment'=>'live','stable_identity'=>$identity]));
Account::withVerifiedBindingWrite(fn()=>$account->update(['live_site'=>'https://billing.example.com']));
$binding=AccountBindings::binding($account);$credentials=['api_key'=>'placeholder-live-key'];
$GLOBALS['liveExpectedKey']=$credentials['api_key'];
$save=static function(string $key,array|string $value)use($server):void {
    $server->settings()->updateOrCreate(['key'=>$key],['encrypted'=>true,'type'=>'string','value'=>is_array($value)?json_encode($value,JSON_THROW_ON_ERROR):$value]);
};
$store=static function(array $credentials)use($save,$binding,$identity):void {
    $save('domains_credentials',$credentials);
    $save('domains_verified_credentials',hash_hmac('sha256',$binding->fingerprint.json_encode($credentials,JSON_THROW_ON_ERROR),(string)config('app.key')));
    $save(LocalAccountEnrollment::KEY,LocalAccountEnrollment::facts($binding,$identity,$credentials));
};
$store($credentials);
$history=['timezone'=>'UTC','lag'=>60,'retention'=>86400];
$evidence=['fingerprint'=>$binding->fingerprint,'site'=>'https://billing.example.com','confirmed_on'=>'2026-10-20',
    'reference'=>'OFFLINE synthetic answer','sha256'=>hash('sha256','OFFLINE synthetic answer'),'currency'=>'USD','complete_history'=>true,
    'history'=>['registration'=>$history,'renewal'=>$history,'transfer'=>$history],
    'portal_controls'=>['reject_invalid_contacts'=>true,'restore_disabled'=>true,'auto_replenishment_disabled'=>true]];
config(['domains.namesilo_live'=>['site'=>'https://billing.example.com','catalogue'=>true,'registration'=>true,'transfer'=>true,'renewal'=>true,'management'=>true,
    'runtime_revision'=>'OFFLINE-v1','accounts'=>[$identity=>['fingerprint'=>$binding->fingerprint,'currency'=>'USD','balance_funding'=>true,
        'daily_call_cap'=>100,'daily_spend_limit_usd'=>'20.00','evidence'=>$evidence]]]]);
$GLOBALS['liveCalls']=[];$ports=new RuntimePorts;app()->instance(RuntimePorts::class,$ports);
// Report both missing installation seams on the base, before testing their retained guards.
$missing=[];
if(!$ports->registered($account)||!$ports->accepted($account)){$missing[]='Built-in Live runtime must compose the existing engines before activation';}
try{$reader=$bindings->reader($server->id);}catch(\Throwable){$missing[]='Built-in Live enrollment must install its admitted holding reader';}
expect($missing===[],implode('; ',$missing));
same([], $GLOBALS['liveCalls'],'Composition and readiness are local');
same(['register'=>false,'transfer'=>false],$ports->acquisitionGates($account),'Deployment flags alone cannot grant acquisition');
expect(!$account->fresh()->processing_enabled,'Composition cannot enable processing');
expect($reader instanceof Driver&&$reader instanceof TransferPoller,'Installed reader includes the existing transfer poller');
expect(!$reader instanceof \Paymenter\Extensions\Servers\Domains\Contracts\AdmittedRegistrarDriver,'Reader exposes no writer');
$context=static fn()=>new RequestContext($binding,RequestOrigin::Background,$clock->now()->modify('+120 seconds'),new RequestBudget(10),$clock,stopOnReadFailure:true);
$usage=fn()=>(new LiveReservations(new NameSiloLiveReservationPolicy))->usage($binding);
same('123.45',$bindings->reader($server->id,true)->balance($context())->evidence()->balance?->amount,'Balance-only entry uses admitted reader');
same(true,$reader->readDomain('example.com',$context())->evidence()->domain?->owned,'Installed holding reads normalize ownership');
$attempt=new AttemptEvidence($binding,new Query('example.com',Action::Transfer,1),'live-runtime-transfer',1,ExecutionRecord::RecordedNull,SendState::PossiblySent,$clock->now()->modify('-1 minute'),$clock->now());
same(TransferStatus::Pending,$reader->pollTransfer($attempt,$context())->result()->evidence()->transfer,'Installed pending poll uses existing status adapter');
same(['used'=>3,'spend_micros'=>0],$usage(),'Read and poll attempts share the existing durable ledger');
$balances=new RegistrarBalances($bindings,$clock,new AccountReadBudget($clock));$balances->daily($account);$balances->daily($account);
same(4,count($GLOBALS['liveCalls']),'Daily balance due claim sends once');
same(4,$usage()['used'],'Daily worker uses admitted accounting');
$composition=static fn()=>(new \ReflectionMethod(RuntimePorts::class,'composition'))->invoke($ports,$account);
$initial=$composition();
foreach(RuntimePorts::TYPES as $key=>$type){expect($initial[$key] instanceof $type,'Real shared runtime port: '.$key);}
expect($initial['observation_dedicated']&&$initial['observation_reader'] instanceof ObservationReader,'Dedicated read-only observation is installed');
expect($ports->observationReader($binding)->bindsTo($binding),'Observation reader has the same immutable account');
$engine=new LiveActivation(new ActivationEvidence);
$receipt=static function(bool $registration=true)use($save,$engine,$binding):void {$save(LiveActivation::KEY,[...$engine->receiptFor($binding),'registration'=>$registration]);};
$receipt();
foreach(['renewal','transfer','management'] as $feature){$save('domains_namesilo_'.$feature,['fingerprint'=>$binding->fingerprint,'enabled'=>true]);}
expect($engine->current($binding),'Synthetic receipt models local activation only');
same(['register'=>true,'transfer'=>true],$ports->acquisitionGates($account),'Current receipt and feature choices enable the installed acquisition gates');
// Even a receipt for this deployment cannot supply its missing contact-creation capability.
config(['domains.namesilo_live.management'=>false]);$receipt();
expect($engine->current($binding),'Contact prerequisite test uses a current receipt, not a stale deployment');
same(['register'=>false,'transfer'=>false],$ports->acquisitionGates($account),'Acquisitions close before checkout when deployment contact creation is Off');
config(['domains.namesilo_live.management'=>true]);$receipt();
$cached=$composition();$features=(new \ReflectionProperty($cached['renewal'],'features'))->getValue($cached['renewal']);
expect($features->renewal&&$features->management,'Current reviewed renewal and management choices reach the shared engines');
$deny=static function(callable $work):void {
    $before=count($GLOBALS['liveCalls']);$refused=false;
    try{$work();}catch(\Throwable $e){$refused=true;expect(!str_contains($e->getMessage().$e->getTraceAsString(),'placeholder-live-key'),'Refusal redacts credentials');}
    expect($refused,'Stale or unadmitted handle must refuse');same($before,count($GLOBALS['liveCalls']),'Refusal performs no physical send');
};
$accepted=(new \ReflectionProperty($cached['renewal'],'accepted'))->getValue($cached['renewal']);
$accepted->requireCurrent($binding);
// Each retained engine also has to honor later withdrawals, without rebuilding its objects.
$save('domains_namesilo_renewal',['fingerprint'=>$binding->fingerprint,'enabled'=>false]);
$deny(fn()=>$accepted->requireCurrent($binding));
$save('domains_namesilo_renewal',['fingerprint'=>$binding->fingerprint,'enabled'=>true]);
$cached=$composition();
$accepted=(new \ReflectionProperty($cached['renewal'],'accepted'))->getValue($cached['renewal']);$accepted->requireCurrent($binding);
$exchange=(new \ReflectionProperty(\Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol::class,'exchange'))->getValue($cached['management_protocol'])->getValue();
$transport=(new \ReflectionFunction($exchange))->getClosureThis();
$credentialCheck=(new \ReflectionProperty(AdmittedTransport::class,'credentials'))->getValue($transport)->getValue();
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>true]);
expect(is_array($credentialCheck(true)),'Current receipt and Orders On pass the final local credential guard');
$receipt(false);same(['register'=>false,'transfer'=>true],$ports->acquisitionGates($account),'Registration receipt Off leaves independently reviewed transfer intact');
$deny(fn()=>$credentialCheck(true));
// Use the engine's real request authority with a synthetic holding snapshot. No provider or queue is involved.
$receipt();$guarded=$composition();
$owner=77;$domain=new \Paymenter\Extensions\Servers\Domains\Models\Domain(['user_id'=>$owner,'status'=>'active']);
$holding=['domain'=>$domain,'account'=>$account->fresh(),'server'=>$server,'staff'=>null,'service'=>new \App\Models\Service(['status'=>'active']),'cancel'=>collect()];
$authority=new \ReflectionMethod($guarded['management'],'authority');
foreach(['lock','privacy_enable','glue_add'] as $action){
    $authority->invoke($guarded['management'],$holding,$owner,$action,$guarded['management_protocol'],true,null,true);
}
$save('domains_namesilo_management',['fingerprint'=>$binding->fingerprint,'enabled'=>false]);
foreach(['lock','privacy_enable','glue_add'] as $action){
    $deny(fn()=>$authority->invoke($guarded['management'],$holding,$owner,$action,$guarded['management_protocol'],true,null,true));
}
$save('domains_namesilo_management',['fingerprint'=>$binding->fingerprint,'enabled'=>true]);
$server->settings()->where('key',LiveActivation::KEY)->delete();
$deny(fn()=>$authority->invoke($guarded['management'],$holding,$owner,'lock',$guarded['management_protocol'],true,null,true));
$receipt();DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);
$cached=$composition();$accepted=(new \ReflectionProperty($cached['renewal'],'accepted'))->getValue($cached['renewal']);$accepted->requireCurrent($binding);
$server->settings()->where('key',LiveActivation::KEY)->delete();
$deny(fn()=>$accepted->requireCurrent($binding));same(['register'=>false,'transfer'=>false],$ports->acquisitionGates($account),'Receipt withdrawal closes writes but preserves recovery composition');
same('123.45',$reader->balance($context())->evidence()->balance?->amount,'Receipt withdrawal retains admitted read-only recovery');
$receipt();
config(['domains.namesilo_live.runtime_revision'=>'OFFLINE-v2']);
$deny(fn()=>$reader->balance($context()));$deny(fn()=>$accepted->requireCurrent($binding));
same(['register'=>false,'transfer'=>false],$ports->acquisitionGates($account),'Changed deployment invalidates write receipts');
config(['domains.namesilo_live.runtime_revision'=>'OFFLINE-v1']);
foreach(['domains.namesilo_live.catalogue','domains.namesilo_live.accounts.'.$identity.'.balance_funding'] as $key){
    config([$key=>false]);expect(!$ports->accepted($account),'Withdrawn admission closes automatic runtime');$deny(fn()=>$reader->balance($context()));config([$key=>true]);
}
config(['app.url'=>'https://copied.example.com']);expect(!$ports->accepted($account),'Copied site cannot reuse Live runtime');$deny(fn()=>$reader->balance($context()));config(['app.url'=>'https://billing.example.com']);
$server->update(['enabled'=>false]);expect(!$ports->accepted($account),'Disabled server cannot compose Live runtime');$deny(fn()=>$reader->balance($context()));$server->update(['enabled'=>true]);
$wrong=new AccountBinding('namesilo',$binding->serverId+1,Environment::Live,LocalAccountEnrollment::PREFIX.'other');
$factory=LiveRuntime::factory($binding);$deny(fn()=>$factory($wrong));
// A cached writer cannot bypass processing Off, even when every feature and receipt is current.
// Call the final credential guard directly so the reservation policy cannot mask this refusal.
$cached=$composition();
$exchange=(new \ReflectionProperty(\Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol::class,'exchange'))->getValue($cached['management_protocol'])->getValue();
$transport=(new \ReflectionFunction($exchange))->getClosureThis();
$credentialCheck=(new \ReflectionProperty(AdmittedTransport::class,'credentials'))->getValue($transport)->getValue();
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>true]);
expect(is_array($credentialCheck(true)),'Current cached credentials allow writes with Orders On');
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);
expect(is_array($credentialCheck(false)),'Orders Off retains current read credentials');
$processingRefused=false;
try{$credentialCheck(true);}catch(\Paymenter\Extensions\Servers\Domains\Services\RuntimeIneligible $e){
    same(\Paymenter\Extensions\Servers\Domains\Services\RuntimeReason::ProcessingOff,$e->reason,'Final credential guard refuses Orders Off');
    $processingRefused=true;
}
expect($processingRefused,'Final credential guard requires processing independently of reservation policy');
$exchange=(new \ReflectionProperty(\Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol::class,'exchange'))->getValue($cached['management_protocol'])->getValue();
$deny(fn()=>$exchange('https://www.namesilo.com/apibatch','domainLock',['domain'=>'example.com'],1000));
$before=$usage();$GLOBALS['liveExpectedKey']='placeholder-rotated-key';$store(['api_key'=>'placeholder-rotated-key']);
$deny(fn()=>$reader->balance($context()));$deny(fn()=>$accepted->requireCurrent($binding));$deny(fn()=>$factory($binding));
expect($ports->accepted($account),'Newly authenticated revision can compose read-only recovery');
same(['register'=>false,'transfer'=>false],$ports->acquisitionGates($account),'Rotation cannot reuse prior write receipt');
$fresh=$bindings->reader($server->id);$fresh->balance($context());
same($before['used']+1,$usage()['used'],'Credential rotation preserves daily ledger lineage');
// A lost read is counted and remains unresolved; no retry or write fallback is installed.
$GLOBALS['liveFail']=true;$before=$usage();$calls=count($GLOBALS['liveCalls']);
$denyFailure=false;try{$fresh->balance($context());}catch(\Throwable){$denyFailure=true;}
expect($denyFailure,'Lost reply remains unresolved');same($calls+1,count($GLOBALS['liveCalls']),'Lost reply sends once');same($before['used']+1,$usage()['used'],'Lost reply retains physical call accounting');
echo "Installed NameSilo Live composition preserves shared engines, receipts, revisions and read accounting.\n";
$nativeSuccessful=true;
