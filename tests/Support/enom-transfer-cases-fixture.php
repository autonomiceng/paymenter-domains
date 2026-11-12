<?php
// Installed socket-free transfer activation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
if(is_file($receipt.'.transfer-fixture')){$resumeTransfer=json_decode(file_get_contents($receipt.'.transfer-fixture'),true,512,JSON_THROW_ON_ERROR);}
require __DIR__.'/enom-transfer-setup.php';
require_once __DIR__.'/reserved-fixtures.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Data\{Environment,HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestTransfer as TransferPolicy, EnomTestRegistration, EnomTestSendLimit, EnomTestSendLimitSchema, RuntimePorts, RuntimeWork, TransferInputs, HistoryAcceptances, OwnedDomains, Refunds};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController;
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
$nativeSuccessful=false;expect(app()->bound(\Paymenter\Extensions\Servers\Domains\Services\TransferPrecheck::class),'Deployment composes transfer precheck before native cart boot');config(['domains.enom_writes.registration'=>false,'domains.enom_writes.renewal'=>true,'domains.enom_test_sends_per_day'=>1000,'domains.enom_test_sends_per_minute'=>100000]);
$clock->at=new DateTimeImmutable($resumeTransfer['at']??'2026-10-22T00:10:00Z');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
if($transferGroup==='arrival'){
foreach([false,'true',1,null] as $off){config(['domains.enom_writes.transfer'=>$off]);expect(!TransferPolicy::enabled($binding),'Strict transfer opt-in');}
config(['domains.enom_writes.transfer'=>true]);foreach(['live','',null] as $env){config(['domains.enom_identity.environment'=>$env]);expect(!TransferPolicy::enabled($binding),'TEST transfer refuses live/unset');}
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>false]);expect(!TransferPolicy::enabled($binding),'Transfer requires TEST catalogue');config(['domains.enom_catalogue.enabled'=>true]);
expect(!TransferPolicy::enabled(new \Paymenter\Extensions\Servers\Domains\Data\AccountBinding('enom',999,Environment::Live,'test-uid')),'Transfer requires verified TEST binding');

$runtimeFactory=EnomTestRegistration::factory($binding);DB::enableQueryLog();DB::flushQueryLog();$composed=$runtimeFactory();same([],DB::getQueryLog(),'Returned transfer runtime factory remains pure');DB::disableQueryLog();same(['register'=>false,'transfer'=>true],$composed['acquisition']->gates(),'Transfer composition independent of registration');
$setupForms=$GLOBALS['enomWire']['forms'];Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])->assertSeeInOrder(['Sales','Registrations','Off','Transfers (Experimental)','Off','Renewals'])->assertDontSee('TEST-SECRET-TOKEN');same($setupForms,$GLOBALS['enomWire']['forms'],'Staff transfer acceptance page zero provider calls');app('request')->setLaravelSession($session);
expect(!TransferPolicy::accepted($binding),'The site allowing transfers does not turn them on');$write('test_transfer',['accept'=>true,'verified'=>true]);expect(TransferPolicy::accepted($binding),'Staff turn TEST transfers on for this account');
$write('publish',[],$tld->id);$transferProduct=app(\Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::class)->product($tld,'transfer');expect($transferProduct!==null,'Current active transfer publication follows real account review');
}else{$transferProduct=app(\Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::class)->product($tld,'transfer');}
$transferState=(object)['name'=>'','bill'=>'','pending'=>true,'charge'=>true];require __DIR__.'/enom-transfer-wire.php';
$reset=static function()use($server,$transferReply):void{Cache::flush();DB::table('domains_read_budgets')->where('server_id',$server->id)->delete();resetWire(['reply'=>$transferReply]);};$reset();
$run=static fn($op)=>(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);
$newTransfer=static function(string $name,bool $paid=true)use($user,$transferProduct,$clock,$binding):Operation {
    Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());try{$cart=commerceCart($user);commerceAdd($cart,$transferProduct,$name);commerceCheckout($user,$cart)->assertHasNoErrors();}
    catch(DomainException $error){installedOfferFailure($binding,$clock,['transfer','renew']);throw $error;}
    $op=Domain::where('domain',$name)->sole()->operations()->sole();if($paid){App\Helpers\ExtensionHelper::addPayment($op->invoice_id,null,$op->amount);}return $op->fresh();
};
$code=static function(Operation $op,string $secret)use($user,$session):void{
    $data=['transfer_code'=>$secret];$r=Request::create('/domains/'.$op->domain_id.'/transfer-code','POST',$data,[],[],['CONTENT_TYPE'=>'application/x-www-form-urlencoded'],http_build_query($data,'','&',PHP_QUERY_RFC1738));
    $r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);$r->request->set('_request_key',FormKeys::issue($r,'owner:'.$op->domain_id.':code'));app(OwnerActionController::class)->code($r,$op->domain_id);
    same($secret,$op->fresh()->payload['epp'],'Owner code exact bytes');expect(!$r->request->has('transfer_code'),'Owner code scrubbed');expect(!str_contains(json_encode($r->session()->all()),$secret),'Code absent from session');expect(!str_contains(DB::table('domains_operations')->find($op->id)->payload,$secret),'Code encrypted at rest');
};
$secret='  Synthetic:Case-Sensitive + % é雪  ';
