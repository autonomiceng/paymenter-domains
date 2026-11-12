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
config(['domains.enom_test_sends_per_day'=>0]);same('Our test send limit is 0, so no Enom test requests are sent.',EnomTestSendLimit::facts('test-uid'),'Zero limit wording is accurate');$beforeCalls=count($GLOBALS['enomWire']['forms']);$beforeUsed=enomTestSendCount();
$balanceBeforeRefusal=DB::table(Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->where('account_fingerprint',$binding->fingerprint)->first();
$capRead=static fn()=>new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+25 seconds'),
    new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(1),$clock,Runtime::limiter());
try{Paymenter\Extensions\Servers\Domains\Services\BalanceReads::read($reader,$capRead(),'manual');throw new LogicException('Zero cap must refuse');}catch(EnomTestCallLimit){}
same((array)$balanceBeforeRefusal,(array)DB::table(Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->where('account_fingerprint',$binding->fingerprint)->first(),'A local cap refusal preserves reviewed USD balance evidence');
same($beforeUsed,enomTestSendCount(),'Zero cap does not increment');same($beforeCalls,count($GLOBALS['enomWire']['forms']),'Zero cap refuses before transport send');
$clock->at=$clock->at->modify('+61 seconds');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());Cache::flush();
$beforeCalls=count($GLOBALS['enomWire']['forms']);$evidenceBefore=array_intersect_key((array)$balanceBeforeRefusal,array_flip(['amount','currency','source','attempted_at','read_at','last_error_code']));
Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])
    ->callAction('checkBalance')->assertNotified(\Filament\Notifications\Notification::make()->danger()->title("Couldn't check balance")->body('Our test send limit is 0, so no Enom test requests are sent.'));
same($beforeCalls,count($GLOBALS['enomWire']['forms']),'Staff Refresh now honors the exhausted cap before transport');
$evidenceAfter=DB::table(Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->where('account_fingerprint',$binding->fingerprint)->first();
same($evidenceBefore,array_intersect_key((array)$evidenceAfter,array_flip(['amount','currency','source','attempted_at','read_at','last_error_code'])),'Staff cap refusal preserves the prior successful USD evidence');
config(['domains.enom_test_sends_per_day'=>'50']);same('Our test send limit on this site is invalid, so no Enom test requests are sent. Ask your site admin to fix it.',EnomTestSendLimit::facts('test-uid'),'Non-integer limit wording says who fixes it');
$beforeCalls=count($GLOBALS['enomWire']['forms']);$beforeUsed=enomTestSendCount();
try{Paymenter\Extensions\Servers\Domains\Services\BalanceReads::read($reader,$capRead(),'manual');throw new LogicException('Non-integer cap must refuse');}catch(EnomTestCallLimit){}
same($beforeUsed,enomTestSendCount(),'Non-integer cap refuses without increment');same($beforeCalls,count($GLOBALS['enomWire']['forms']),'Non-integer cap refuses before transport send');
config(['domains.enom_test_sends_per_day'=>500]);
$clock->at=$clock->at->modify('+61 seconds');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());Cache::flush();
$beforeCalls=count($GLOBALS['enomWire']['forms']);$beforeUsed=enomTestSendCount();$faultFacts=EnomTestSendLimit::facts('test-uid');$counterFault=true;
DB::listen(static function($query)use(&$counterFault):void{if($counterFault&&str_starts_with(strtolower($query->sql),'insert')&&str_contains($query->sql,EnomTestSendLimitSchema::SENDS)){$counterFault=false;throw new RuntimeException('Synthetic counter error');}});
Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])
    ->callAction('checkBalance')->assertNotified(\Filament\Notifications\Notification::make()->danger()->title("Couldn't check balance")->body($faultFacts));
expect(!$counterFault,'Synthetic counter error reached the send limit');same($beforeUsed,enomTestSendCount(),'Counter error refusal rolls back its attempt');same($beforeCalls,count($GLOBALS['enomWire']['forms']),'Counter error refuses before transport');

$nativeSuccessful=true;
