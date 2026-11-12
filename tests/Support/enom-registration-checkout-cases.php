<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\{Currency,Server};
use Illuminate\Support\Facades\{Auth,Cache,DB,Queue};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,AttemptEvidence,DispatchSeal,Environment,ExecutionRecord,HistoryAcceptance,Query,RequestOrigin,SendState};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, EnomTestCalls, EnomTestCallLimit, EnomTestRegistration, EnomTestSendLimit, EnomTestSendLimitSchema, Installation, NativeCatalogue, ObservationWork, Runtime, RuntimePorts, RuntimeWork, StaffSettingsReview as Review, StaffSettingsWrites, Storefront};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
require __DIR__.'/enom-test-sales-fixture.php';
$reader=$bindings->reader($server->id,true);$generalReader=$bindings->reader($server->id);
$unpaidOp=Domain::where('domain','unpaid-test-placeholder.com')->sole()->operations()->sole();
$op=Domain::where('domain','paid-test-placeholder.com')->first()?->operations()->sole();
app()->instance(Paymenter\Extensions\Servers\Domains\Services\PslTransport::class,new Paymenter\Extensions\Servers\Domains\Services\PslTransport(static fn()=>throw new Paymenter\Extensions\Servers\Domains\Services\PslFailure('transport_failed')));
// Isolate the limiter after synthetic clock jumps, then enter the worker on the
// last second to verify preflight rollover with the reviewed price still current.
config(['domains.enom_test_sends_per_day'=>500]);$clock->at=new DateTimeImmutable('2026-10-21T23:59:59Z');Cache::flush();
DB::table('domains_read_budgets')->where('server_id',$server->id)->delete();$midnightOp=$new(placeholderComFixtureName('preflight-midnight-placeholder.com'));$midnightUsed=enomTestSendCount();$before=count($GLOBALS['enomWire']['forms']);
$GLOBALS['advanceDuringPreflight']=true;$worker->operation($midnightOp->id);
same(['Check','PE_GetProductPrice','GetBalance','Purchase'],array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),'Preflight may cross UTC midnight before arming Purchase; reconciliation waits for completion proof');
same('pending',$midnightOp->fresh()->state,'Midnight during preflight does not create a false cap hold');
same($midnightUsed+4,enomTestSendCount(),'Preflights and Purchase each record one attempt across UTC midnight');
expect($a->fresh()->processing_enabled,'Catalogue-off regression starts after staff enabled processing');$catalogueCart=commerceCart($user);commerceAdd($catalogueCart,$product,placeholderComFixtureName('catalogue-off-processing-placeholder.com'));config(['domains.enom_catalogue.enabled'=>false]);$counts=[App\Models\Order::count(),App\Models\Invoice::count(),App\Models\InvoiceTransaction::count(),count($GLOBALS['enomWire']['forms'])];
commerceCheckout($user,$catalogueCart)->assertDispatched('notify',fn($event,$params)=>$params[0]['type']==='error');same($counts,[App\Models\Order::count(),App\Models\Invoice::count(),App\Models\InvoiceTransaction::count(),count($GLOBALS['enomWire']['forms'])],'Catalogue key-off with processing enabled creates no order, invoice, payment, or Purchase');config(['domains.enom_catalogue.enabled'=>true]);
expect($a->fresh()->processing_enabled,'Key-off regression starts after staff enabled processing');$closedCart=commerceCart($user);commerceAdd($closedCart,$product,placeholderComFixtureName('key-off-processing-placeholder.com'));config(['domains.enom_writes.registration'=>null]);$counts=[App\Models\Order::count(),App\Models\Invoice::count(),App\Models\InvoiceTransaction::count(),count($GLOBALS['enomWire']['forms'])];
commerceCheckout($user,$closedCart)->assertDispatched('notify',fn($event,$params)=>$params[0]['type']==='error');same($counts,[App\Models\Order::count(),App\Models\Invoice::count(),App\Models\InvoiceTransaction::count(),count($GLOBALS['enomWire']['forms'])],'Key-off with processing enabled creates no order, invoice, payment, or Purchase');config(['domains.enom_writes.registration'=>true]);
Auth::setUser($staff);config(['domains.enom_writes.transfer'=>true]);$write('test_transfer',['accept'=>true,'verified'=>true]);
$clock->at=$clock->now()->modify('+1 second');$tld=installedOfferEvidence($binding,$clock,true);
$write('tld_settings',['enabled'=>true,'register'=>true,'transfer'=>true,'renew'=>true,'min_years'=>1,'max_years'=>1,'verified'=>true],$tld->id);
$write('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>true],$tld->id);
$server->settings()->create(['key'=>'domains_transfer_endings','value'=>'com']);$write('publish',[],$tld->id);$transferProduct=app(NativeCatalogue::class)->product($tld,'transfer');
// Retired TEST-only mixed-cart prohibition: offer-commerce-test.php proves active mixed-account
// success and whole-basket rollback; this fixture retains the independent transfer processing fence.
$selection=app(Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class)->selection('com','transfer',1,$clock->now());DB::table('domains_accounts')->where('id',$a->id)->update(['processing_enabled'=>false]);
try{DB::transaction(fn()=>app(Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class)->admit([$selection],$clock->now()));throw new LogicException('TEST registration does not exempt transfer from processing.');}catch(DomainException){}DB::table('domains_accounts')->where('id',$a->id)->update(['processing_enabled'=>true]);
resetWire();$clock->at=new DateTimeImmutable('2026-10-22T00:00:01Z');
file_put_contents($receipt,json_encode(retained(),JSON_THROW_ON_ERROR));$nativeSuccessful=true;
echo "Installed TEST registration, safe cap holds, captured-ID reconciliation and retained cap passed.\n";

$nativeSuccessful=true;
