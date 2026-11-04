<?php
// Native fixture exercises real enrollment, staff review and installed composition, which a unit cannot prove.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/namesilo-test-activation-test.php';
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueAcceptances,LiveReservationLimit,LiveReservations,RuntimePorts,StaffSettingsWrites};
function curl_init(): object {return new \stdClass;}
function curl_setopt_array($h,array $options): bool {$GLOBALS['testRuntimeOptions']=array_replace($GLOBALS['testRuntimeOptions']??[],$options);return true;}
function curl_getinfo($h,int $option): int {return 200;}
function curl_close($h): void {}
function curl_exec($h): bool {
    $o=$GLOBALS['testRuntimeOptions'];$url=parse_url($o[CURLOPT_URL]);
    same('ote.namesilo.com',$url['host'],'Installed TEST transport never selects Live');
    same('/apibatch/getAccountBalance',$url['path'],'Runtime reads use their declared batch route');
    same(0,\Illuminate\Support\Facades\DB::transactionLevel(),'Physical TEST reads stay outside locks');
    $GLOBALS['testRuntimeCalls']=($GLOBALS['testRuntimeCalls']??0)+1;
    $body=json_encode(['request'=>['operation'=>'getAccountBalance'],'reply'=>['code'=>300,'detail'=>'success','balance'=>'123.45']]);
    return ($o[CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);
}
$binding=CatalogueAcceptances::binding($account->fresh());$ports=app(RuntimePorts::class);
expect($ports->accepted($account->fresh()),'Installed composition includes genuine acquisition, renewal and management engines');
same(['register'=>true,'transfer'=>true],$ports->acquisitionGates($account->fresh()),'TEST acquisition gates are installed');
same(null,$GLOBALS['testRuntimeCalls']??null,'Local activation and composition never send');
CatalogueAcceptances::save($binding,0,['com'=>['register'=>[1],'renew'=>[1],'transfer'=>[1],'registry'=>true,'renewal_safe'=>true]]);
$bindings=app(AccountBindings::class);$clock=app(Clock::class);
$reader=$bindings->reader($binding->serverId);
$context=static fn()=>new RequestContext($binding,RequestOrigin::Background,$clock->now()->modify('+120 seconds'),new RequestBudget(5),$clock);
same('123.45',$reader->balance($context())->evidence()->balance?->amount,'Installed TEST reader returns exact normalized funds');same(1,$GLOBALS['testRuntimeCalls'],'Built-in read factory reaches the admitted TEST wire');
$ledger=new LiveReservations(new TestReservationPolicy,Environment::Test);
same(1,$ledger->usage($binding)['used'],'TEST reads reserve their separate daily budget');
$refuses=static function(callable $work):void {try{$work();}catch(\Throwable){return;}throw new \RuntimeException('Expected refusal.');};
$refuses(fn()=>(new LiveReservations(new TestReservationPolicy))->usage($binding));
config(['domains.namesilo_test.catalogue'=>false]);
expect(!$ports->accepted($account->fresh()),'Deployment withdrawal closes existing TEST composition');
$before=$GLOBALS['testRuntimeCalls'];$reader->balance($context());same($before,$GLOBALS['testRuntimeCalls'],'Existing readers cannot bypass withdrawal');
config(['domains.namesilo_test.catalogue'=>true]);
$writes->run($review('processing'),['enabled'=>false,'confirmed'=>true]);
expect(!$account->fresh()->processing_enabled,'Reviewed Orders Off remains available');
config(['cache.default'=>'array']);
$refuses(fn()=>$writes->run($review('processing'),['enabled'=>true,'confirmed'=>true]));
expect(!$account->fresh()->processing_enabled,'Orders cannot bypass the shared-worker prerequisite');
config(['cache.default'=>'database']);
expect($ports->accepted($account->fresh()),'Processing pause preserves the read and reconciliation runtime');
$refuses(fn()=>(new AdmittedTransport($binding,static fn()=>['api_key'=>'synthetic-ote-key']))->exchange()('https://www.namesilo.com/apibatch','getAccountBalance',[],1000));
same($before,$GLOBALS['testRuntimeCalls'],'A TEST handle cannot select a Live origin');
$writes->run($review('configure'),['registrar'=>'namesilo','environment'=>Environment::Test->value,'credentials'=>['api_key'=>'synthetic-rotated-key']]);
$reader->balance($context());same($before,$GLOBALS['testRuntimeCalls'],'Previously constructed readers refuse a credential rotation');
same('OK',\Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::runtime($account->fresh())['value'],'Fresh authenticated key can compose a new TEST runtime');
config(['domains.namesilo_account.environment'=>'live']);
expect(!$ports->accepted($account->fresh()),'Changing deployment to Live cannot reuse TEST authority');
same($before,$GLOBALS['testRuntimeCalls'],'All refusal checks are socket free');
$nativeSuccessful=true;
