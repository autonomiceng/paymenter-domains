<?php
// Synthetic installed bindings and wire only; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/native-app.php';
require_once __DIR__.'/Support/FakeRegistrar.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{AdmittedRegistrarDriver,CatalogueReader,TransferPoller};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,AttemptEvidence,Disposition,Environment,ExecutionRecord,Query,RequestBudget,RequestContext,RequestOrigin,SendState,TransferStatus};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueAcceptances,Installation,LiveReservations,LocalAccountEnrollment,RegistrarFactories,RegistrarRegistry,RuntimePorts};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
expect(method_exists(AccountBindings::class,'nameSiloRuntimeReaderFactory'),'Installed NameSilo needs an admitted runtime reader factory');
function hrtime(bool $number=false): int {return 0;}
function curl_init(): object {return new \stdClass;}
function curl_setopt_array($h,array $options): bool {$GLOBALS['runtimeWire']['options']=array_replace($GLOBALS['runtimeWire']['options'],$options);return true;}
function curl_getinfo($h,int $option): int {return 200;}
function curl_close($h): void {}
function curl_exec($h): bool {
    same(0,DB::transactionLevel(),'Every physical runtime read is outside locks');
    $o=$GLOBALS['runtimeWire']['options'];$url=parse_url($o[CURLOPT_URL]);parse_str($url['query'],$params);
    same('www.namesilo.com',$url['host'],'Live reader never selects another origin');
    $command=basename($url['path']);$GLOBALS['runtimeWire']['calls'][]=[$url['path'],$params];
    expect(!in_array($command,['registerDomain','renewDomain','transferDomain','contactAdd','removeAutoRenewal'],true),'Read runtime cannot mutate');
    if($GLOBALS['runtimeWire']['fail']){throw new \RuntimeException($o[CURLOPT_URL]);}
    $data=$GLOBALS['runtimeWire']['fixtures'][$command];
    $body=json_encode($data,JSON_THROW_ON_ERROR);
    return ($o[CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);
}
ini_set('zend.exception_ignore_args','0');(new Installation)->install();
$clock=new FakeClock(new \DateTimeImmutable('2026-11-02T12:00:00Z'));
config(['app.url'=>'https://billing.example.com','domains.namesilo_account.environment'=>'live',
    'domains.namesilo_sandbox.enabled'=>false,'domains.namesilo_live.site'=>'https://billing.example.com','domains.namesilo_live.catalogue'=>true]);
$registry=new RegistrarRegistry;expect(RegistrarFactories::bootstrap($registry),'Local enrollment composition available');
$bindings=new AccountBindings($registry);app()->instance(AccountBindings::class,$bindings);
$server=Server::create(['name'=>'Synthetic runtime','type'=>'server','extension'=>'Domains','enabled'=>true]);
$identity=LocalAccountEnrollment::PREFIX.'runtime-account';
$account=Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$server->id,'registrar_key'=>'namesilo','environment'=>'live','stable_identity'=>$identity]));
Account::withVerifiedBindingWrite(fn()=>$account->update(['live_site'=>'https://billing.example.com']));
$binding=CatalogueAcceptances::binding($account);$credentials=['api_key'=>'placeholder-runtime-key'];
$facts=LocalAccountEnrollment::facts($binding,$identity,$credentials);
$save=static function(string $key,string $value)use($server):void {
    $setting=$server->settings()->firstOrNew(['key'=>$key]);$setting->encrypted=true;$setting->type='string';$setting->value=$value;$setting->save();
};
$store=static function(array $credentials,array $facts)use($save,$binding):void {
    $save('domains_credentials',json_encode($credentials,JSON_THROW_ON_ERROR));
    $save('domains_verified_credentials',hash_hmac('sha256',$binding->fingerprint.json_encode($credentials,JSON_THROW_ON_ERROR),(string)config('app.key')));
    $save(LocalAccountEnrollment::KEY,json_encode($facts,JSON_THROW_ON_ERROR));
};
$store($credentials,$facts);
config(['domains.namesilo_live.accounts'=>[$identity=>['fingerprint'=>$binding->fingerprint,'currency'=>'USD','balance_funding'=>true,'daily_call_cap'=>100,'daily_spend_limit_usd'=>'20.00']]]);
CatalogueAcceptances::save($binding,0,['com'=>['register'=>[1],'renew'=>[1],'transfer'=>[1],'registry'=>true,'renewal_safe'=>true]]);
$GLOBALS['runtimeWire']=['calls'=>[],'options'=>[],'fail'=>false,'fixtures'=>json_decode(file_get_contents(__DIR__.'/fixtures/synthetic-success.json'),true,32,JSON_THROW_ON_ERROR)];
$context=static fn(RequestOrigin $origin=RequestOrigin::Background,int $budget=10)=>new RequestContext($binding,$origin,$clock->now()->modify('+240 seconds'),new RequestBudget($budget),$clock,stopOnReadFailure:true);
$deny=static function(callable $work):void {
    $before=count($GLOBALS['runtimeWire']['calls']);$refused=false;
    try{$work();}catch(\Throwable $e){$refused=true;expect(!str_contains($e->getMessage().$e->getTraceAsString(),'placeholder-runtime-key'),'Refusal redacts key');same(null,$e->getPrevious(),'No unsafe chained error');}
    expect($refused,'Unadmitted runtime read refused');same($before,count($GLOBALS['runtimeWire']['calls']),'Refusal cannot send or fall back');
};
$deny(fn()=>$bindings->reader($server->id));
$factory=$bindings->nameSiloRuntimeReaderFactory($binding,$clock);$deny(fn()=>$factory($binding));
$accepted=true;$revision=$facts['credential_revision'];
// Capture controls by reference so revocation applies to already-created readers.
$accept=static function(AccountBinding $b,string $r)use(&$accepted,&$revision,$binding):bool{return $accepted&&$b->matches($binding)&&$r===$revision;};
$factory=$bindings->nameSiloRuntimeReaderFactory($binding,$clock,$accept);
same([], $GLOBALS['runtimeWire']['calls'],'Factory construction performs no provider work');
$registry->registerBoundReader($binding,$factory);
$deny(fn()=>$registry->registerBoundReader($binding,$factory));
$reader=$bindings->reader($server->id);
expect($reader instanceof Driver&&$reader instanceof CatalogueReader&&$reader instanceof TransferPoller,'Shared readers and transfer polling use the genuine driver');
expect(!$reader instanceof AdmittedRegistrarDriver,'Reader factory exposes no writer');
same([], $GLOBALS['runtimeWire']['calls'],'Creating a runtime reader performs no provider work');
$usage=fn()=>(new LiveReservations(new NameSiloLiveReservationPolicy))->usage($binding);
$balance=$bindings->reader($server->id,true)->balance($context(RequestOrigin::Interactive));
same('123.45',$balance->evidence()->balance?->amount,'Balance-only callers also use the admitted transport');
same('/api/getAccountBalance',$GLOBALS['runtimeWire']['calls'][0][0],'Interactive reads use api');
$domain=$reader->readDomain('example.com',$context())->evidence()->domain;
same('example.com',$domain?->name,'Daily reader retains exact holding');same(true,$domain?->owned,'Daily reader retains ownership');
same('/apibatch/getDomainInfo',$GLOBALS['runtimeWire']['calls'][1][0],'Daily reads use apibatch');
same('12.34',$reader->quote(new Query('example.com',Action::Register,1),$context())->evidence()->quote?->cost->amount,'Accepted annual account prices remain exact');
same(['used'=>3,'spend_micros'=>0],$usage(),'Each physical read counted once, no spend');
$before=count($GLOBALS['runtimeWire']['calls']);
$reader->quote(new Query('example.com',Action::Register,2),$context());
same($before,count($GLOBALS['runtimeWire']['calls']),'No invented multi-year reads');
// Pending transfer polling uses the existing short path, never replaying the paid transfer.
$GLOBALS['runtimeWire']['fixtures']['checkTransferStatus']=['request'=>['operation'=>'checkTransferStatus'],'reply'=>['code'=>300,'detail'=>'success','date'=>'2026-11-02','status'=>'Pending at Registry','message'=>'synthetic pending']];
$attempt=new AttemptEvidence($binding,new Query('example.com',Action::Transfer,1),'runtime-transfer',1,ExecutionRecord::RecordedNull,SendState::PossiblySent,$clock->now()->modify('-1 minute'),$clock->now());
$polled=$reader->pollTransfer($attempt,$context());
same(TransferStatus::Pending,$polled->result()->evidence()->transfer,'Existing pending transfer vocabulary retained');
same($before+1,count($GLOBALS['runtimeWire']['calls']),'Pending poll is one physical status read');
$before=count($GLOBALS['runtimeWire']['calls']);$beforeUsage=$usage();
$balances=new \Paymenter\Extensions\Servers\Domains\Services\RegistrarBalances($bindings,$clock,new \Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock));
$balances->daily($account);
same($before+1,count($GLOBALS['runtimeWire']['calls']),'Existing daily worker uses the installed reader');
same('/apibatch/getAccountBalance',end($GLOBALS['runtimeWire']['calls'])[0],'Daily balance uses batch route');
same($beforeUsage['used']+1,$usage()['used'],'Daily worker consumes one durable physical call');
$balances->daily($account);same($before+1,count($GLOBALS['runtimeWire']['calls']),'Existing daily due claim prevents duplicate reads');
$ports=new RuntimePorts;expect(!$ports->registered($account)&&!$ports->accepted($account),'Read installation installs no acquisition, renewal or management runtime');
same(false,$account->fresh()->processing_enabled,'Read installation does not activate processing');
$accepted=false;$deny(fn()=>$reader->balance($context()));$deny(fn()=>$bindings->reader($server->id));$accepted=true;
config(['domains.namesilo_live.catalogue'=>false]);$deny(fn()=>$reader->balance($context()));config(['domains.namesilo_live.catalogue'=>true]);
config(['app.url'=>'https://copied.example.com']);$deny(fn()=>$reader->balance($context()));config(['app.url'=>'https://billing.example.com']);
$server->update(['enabled'=>false]);$deny(fn()=>$reader->balance($context()));$server->update(['enabled'=>true]);
$wrong=new AccountBinding('namesilo',$binding->serverId+1,Environment::Live,LocalAccountEnrollment::PREFIX.'other');
$deny(fn()=>$factory($wrong));
// Rotation invalidates cached readers and the external revision-specific acceptance, preserving budget lineage.
$beforeUsage=$usage();$old=$credentials;$credentials=['api_key'=>'placeholder-rotated-key'];$facts=LocalAccountEnrollment::facts($binding,$identity,$credentials);$store($credentials,$facts);
$deny(fn()=>$reader->balance($context()));$deny(fn()=>$bindings->reader($server->id));
$revision=$facts['credential_revision'];$reader=$bindings->reader($server->id);$reader->balance($context());
same($beforeUsage['used']+1,$usage()['used'],'Accepted rotation retains the same durable call ledger');
same('placeholder-rotated-key',end($GLOBALS['runtimeWire']['calls'])[1]['key'],'New read uses freshly validated key');
$setting=$server->settings()->where('key','domains_credentials')->sole();$setting->value=json_encode($old,JSON_THROW_ON_ERROR);$setting->save();
$deny(fn()=>$reader->balance($context()));$store($credentials,$facts);
// A transport timeout remains unresolved and counted, without fallback or a retry.
$GLOBALS['runtimeWire']['fail']=true;$before=count($GLOBALS['runtimeWire']['calls']);$beforeUsage=$usage();
$denyTimeout=false;try{$reader->balance($context());}catch(\Throwable $e){$denyTimeout=true;expect(!str_contains($e->getMessage().$e->getTraceAsString(),'placeholder-rotated-key'),'Timeout redacts rotated key');}
expect($denyTimeout,'Timeout remains unknown');same($before+1,count($GLOBALS['runtimeWire']['calls']),'Lost reply has exactly one physical attempt');same($beforeUsage['used']+1,$usage()['used'],'Lost reply retains its call');
echo "Installed NameSilo read factories enforce admission, revision-safe reads, accounting and shared polling.\n";$nativeSuccessful=true;
