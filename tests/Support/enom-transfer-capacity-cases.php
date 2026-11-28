<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts};
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionResult, OperationAttempts};
use Paymenter\Extensions\Servers\Domains\Staff\Release\HeldRelease;
use Paymenter\Extensions\Servers\Domains\Data\{Environment,HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestTransfer as TransferPolicy, EnomTestRegistration, EnomTestSendLimit, EnomTestSendLimitSchema, RuntimePorts, RuntimeWork, TransferInputs, HistoryAcceptances, OwnedDomains, Refunds};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController;
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
$transferGroup='capacity';
require __DIR__.'/enom-transfer-cases-fixture.php';
$transferState->pending=true;$reset();$after=$newTransfer(placeholderComFixtureName('code-after-pay-placeholder.com'));$reset();$run($after);same('awaiting_code',$after->fresh()->state,'Payment waits for code');same([],$GLOBALS['enomWire']['forms'],'No code no provider calls');$code($after,$secret);$used=DB::table(EnomTestSendLimitSchema::SENDS)->count();$creates=DB::table(EnomTestSendLimitSchema::SENDS)->where('command','TP_CreateOrder')->count();config(['domains.enom_test_sends_per_day'=>EnomTestSendLimit::usage('test-uid')['day']+6]);$reset();$run($after);same(['held','enom_test_transfer_call_cap'],[$after->fresh()->state,$after->fresh()->safe_code],'Cap before transfer held unsent');same(6,count($GLOBALS['enomWire']['forms']),'Cap permits preflight reads only');expect(!in_array('TP_CreateOrder',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Cap zero transfer writes');same([$used+6,$creates],[DB::table(EnomTestSendLimitSchema::SENDS)->count(),DB::table(EnomTestSendLimitSchema::SENDS)->where('command','TP_CreateOrder')->count()],'Refused TP_CreateOrder records no attempt');config(['domains.enom_test_sends_per_day'=>1000]);
$capViewer=Auth::user();Auth::setUser($staff);
expect(OperationActions::relevant('release',$after->fresh()),'Retained transfer cap proof offers release');
same('release',RequestFacts::notice($after->fresh())['action']??null,'Retained transfer cap notice offers release');
Auth::setUser($capViewer);
$job = static fn (Operation $op) => (new RunOperation((int) $op->id))->handle(new RuntimeWork($clock, new RuntimePorts));
$release = static function (Operation $op) use ($staff, $clock): void {
    $domain = $op->domain->fresh();
    $review = \Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket(
        (int) $staff->id, (int) $domain->id, (int) $op->id, 'release',
        \Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($op->fresh(), $domain), $clock,
    );
    app(\Paymenter\Extensions\Servers\Domains\Services\StaffOperations::class)->run(
        $staff, $domain->id, $op->id, 'release', $review, 'Capacity restored for this definitely unsent transfer',
    );
};
$releaseAndDefer = static function (Operation $op) use ($release, $binding, $job, $reset): void {
    $release($op);
    $reset();
    $limiter = \Paymenter\Extensions\Servers\Domains\Services\Runtime::limiter();
    $read = $limiter->acquire($binding, \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background);
    $job($op);
    same(['queued', 'write_rate_limited', []], [$op->fresh()->state, $op->fresh()->safe_code, $GLOBALS['enomWire']['forms']],
        'Released transfer defers with no provider calls when reads are saturated');
    $limiter->release($binding, \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $read);
};
$purchases = static fn (): int => count(array_keys(array_column($GLOBALS['enomWire']['forms'], 'Command'), 'TP_CreateOrder', true));
$releaseAndDefer($after);
$expired = AcquisitionResult::window($after->fresh());
$clock->at = $expired->end->modify('+1 second');
expect($clock->now() < $expired->inputCutoff, 'The transfer input is still valid when acquisition bounds expire');
$reset();
$job($after);
same(['held', $expired->expiryReason, 0], [$after->fresh()->state, $after->fresh()->safe_code, $purchases()],
    'An earlier cap refusal cannot admit the replacement after its released window expires');

$within = $newTransfer(placeholderComFixtureName('within-capacity-window-placeholder.com'));
$code($within, $secret);
config(['domains.enom_test_sends_per_day' => EnomTestSendLimit::usage('test-uid')['day'] + 6]);
$reset();
$job($within);
same('enom_test_transfer_call_cap', $within->fresh()->safe_code, 'A separate transfer reaches the same cap hold');
config(['domains.enom_test_sends_per_day' => 1000]);
$releaseAndDefer($within);
$deferred = $within->fresh()->acquisitionAttemptFacts()->token;
$window = AcquisitionResult::window($within->fresh());
$clock->at = $clock->now()->modify('+30 seconds');
$reset();
$job($within);
same(['uncertain', 1], [$within->fresh()->state, $purchases()], 'The in-window replacement sends exactly once');
expect($deferred->claimKey !== $within->fresh()->acquisitionAttemptFacts()->token->claimKey, 'The replacement changes the worker token');
expect($window == AcquisitionResult::window($within->fresh()), 'A replacement keeps the exact admission');
$reset();$balanceHold=$newTransfer(placeholderComFixtureName('transfer-balance-hold-placeholder.com'));$code($balanceHold,$secret);DB::table(\Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->update(['last_error_code'=>'balance_unknown']);$reset();$run($balanceHold);same('uncertain',$balanceHold->fresh()->state,'A cached balance failure cannot block fresh transfer preflight');same(1,count(array_filter($GLOBALS['enomWire']['forms'],static fn($p)=>$p['Command']==='TP_CreateOrder')),'Fresh transfer preflight permits exactly one synthetic transfer');DB::table(\Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->update(['last_error_code'=>null]);
$reset();$sealed=$newTransfer(placeholderComFixtureName('transfer-sealed-unsent-placeholder.com'));$code($sealed,$secret);$reset();$balanceReads=0;$GLOBALS['enomWire']['during']=static function($p)use(&$balanceReads):void{if($p['Command']==='GetBalance'&&++$balanceReads===2){$GLOBALS['enomWire']['configure']=static function():void{$GLOBALS['enomWire']['nanoseconds']=30000000000;};}};$run($sealed);same(['held','enom_test_transfer_call_cap'],[$sealed->fresh()->state,$sealed->fresh()->safe_code],'Sealed deadline refusal remains definitely unsent');same(6,count($GLOBALS['enomWire']['forms']),'Sealed deadline makes six reads and no transfer write');same('purchase',$sealed->fresh()->attempt['test_call_refusals'][0]['phase'],'Retained sealed refusal proof');expect(!isset($sealed->fresh()->attempt['seal']),'Only proven unconsumed transfer reservation permits seal reset');

$notStarted = $newTransfer(placeholderComFixtureName('expired-before-cap-worker-placeholder.com'));
$code($notStarted, $secret);
config(['domains.enom_test_sends_per_day' => EnomTestSendLimit::usage('test-uid')['day'] + 6]);
$reset();
$job($notStarted);
config(['domains.enom_test_sends_per_day' => 1000]);
$originalRefusal = $notStarted->fresh()->acquisitionAttemptFacts();
same(['enom_test_transfer_call_cap', true, 0], [$notStarted->fresh()->safe_code, $originalRefusal?->sealed(), $purchases()],
    'Capacity stops a sealed transfer before the provider call');
$release($notStarted);
$clock->at = AcquisitionResult::window($notStarted->fresh())->end->modify('+1 second');
$reset();
$job($notStarted);
same(['held', 0, true], [$notStarted->fresh()->state, $purchases(), $notStarted->fresh()->acquisitionAttemptFacts()->token == $originalRefusal->token],
    'An expired cap release with no replacement worker holds on the original attempt');
same(null, OperationAttempts::testCapacityRefusal($originalRefusal), 'The original cap refusal has spent its one capacity release');
Auth::setUser($staff);
foreach ([$notStarted, $after] as $expiredOrder) {
    expect(HeldRelease::offered($expiredOrder->fresh(), $clock->now()), 'Expired cap release permits staff recovery on exact unsent proof');
    $release($expiredOrder);
    $reset();
    $job($expiredOrder);
    same(['uncertain', 1], [$expiredOrder->fresh()->state, $purchases()], 'A new reviewed admission sends the expired transfer once');
    $clock->at = $clock->now()->modify('+31 seconds');
}
Auth::setUser($capViewer);

file_put_contents($receipt.'.transfer-fixture',json_encode(['staff'=>$staff->id,'user'=>$user->id,'server'=>$server->id,'tld'=>$tld->id,'at'=>$clock->now()->format(DATE_ATOM)],JSON_THROW_ON_ERROR));
$nativeSuccessful=true;
