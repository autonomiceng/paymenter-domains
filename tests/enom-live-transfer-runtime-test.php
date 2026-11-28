<?php
// Native paid runtime and physical transfer guards; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$liveTransferRuntime=true;
require __DIR__.'/enom-live-transfer-test.php';

use App\Models\Credit;
use Illuminate\Support\Facades\{Auth, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions;
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionPhase, OperationAttempts};
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, ReconciliationEvidence, Refunds, Runtime, RuntimeWork, StaffOperationReview, StaffOperations, TransferInputs};

$nativeSuccessful = false;
$instant = $clock->now();
$setTime = static function (DateTimeImmutable $date) use ($clock): void {
    $clock->at = $date;
    Carbon\Carbon::setTestNow($date);
    Carbon\CarbonImmutable::setTestNow($date);
};
$atSeal = $atClaim = null;
Operation::updated(static function (Operation $row) use (&$atSeal, &$atClaim): void {
    if ($atClaim && $row->action === 'transfer' && $row->state === 'running' && $row->isDirty('state')) {
        DB::afterCommit($atClaim);
        $atClaim = null;
    }
    if ($atSeal && $row->action === 'transfer' && $row->state === 'running'
        && $row->isDirty('transition_revision') && $row->acquisitionAttemptFacts()?->sealed()) {
        DB::afterCommit($atSeal);
        $atSeal = null;
    }
});
$newPaidTransfer = static function (string $label) use ($user, $transferProduct): Operation {
    $cart = commerceCart($user);
    $domainName = placeholderComFixtureName('live-'.str_replace(' ', '-', $label).'-placeholder.com');
    commerceAdd($cart, $transferProduct, $domainName);
    commerceCheckout($user, $cart)->assertHasNoErrors();
    $order = Domain::where('domain', $domainName)->sole()->operations()->sole();
    App\Helpers\ExtensionHelper::addPayment($order->invoice_id, null, $order->amount);
    $request = Illuminate\Http\Request::create('/code', 'POST', ['transfer_code' => 'SyntheticOnly']);
    $request->setUserResolver(fn () => $user);
    app(TransferInputs::class)->submitCode($request, $order->id);
    return $order->fresh();
};
$priceTransfers = [];
foreach (['before claim', 'before seal', 'after capacity deferral'] as $phase) {
    $priceTransfers[$phase] = $newPaidTransfer('expired '.$phase);
}
$cutoffOrders = [];
foreach (['full budget', 'short budget', 'wire refusal'] as $case) {
    $setTime($clock->now()->modify('+61 seconds'));
    $reset();
    Auth::setUser($staff);
    installedOfferEvidence($binding, $clock, true);
    $cutoffOrders[$case] = $newPaidTransfer('cutoff '.$case);
}
$priceResults = [];
Auth::setUser($staff);
$setTime($instant->modify('+1800 seconds'));
foreach ($priceTransfers as $phase => $order) {
    $setTime($clock->now()->modify('+31 seconds'));
    installedOfferEvidence($binding, $clock, true);
    $name = $order->domain->domain;
    $runOrder = static fn () => (new RunOperation($order->id))->handle(app(RuntimeWork::class));
    $purchases = static fn () => count(array_filter($GLOBALS['enomWire']['forms'], static fn ($form) => $form['Command'] === 'TP_CreateOrder'));
    $reset();
    $GLOBALS['enomWire']['reply'] = static function (array $form) use ($setupReply): string {
        $reply = $setupReply($form);
        return $form['Command'] === 'PE_GetProductPrice' ? str_replace('10.0000', '20.0000', $reply) : $reply;
    };
    $runOrder();
    $order->refresh();
    same(['held', 'registrar_price_increased', 0], [$order->state, $order->safe_code, $purchases()],
        'An above-ceiling provider quote produces a funded price hold '.json_encode([$order->state, $order->safe_code, array_column($GLOBALS['enomWire']['forms'], 'Command')], JSON_THROW_ON_ERROR));
    $funding = $order->funding['claim'];
    $releasePrice = static function () use ($staff, $order, $clock): void {
        Auth::setUser($staff);
        $order->refresh();
        $ticket = StaffOperationReview::ticket($staff->id, $order->domain_id, $order->id, 'release',
            StaffOperationReview::stamp($order, $order->domain), $clock);
        (new StaffOperations($clock))->run($staff, $order->domain_id, $order->id, 'release', $ticket, 'Review the next quote');
    };
    $releaseAt = $clock->now()->modify('+31 seconds');
    $setTime($releaseAt);
    try { $releasePrice(); } catch (\Throwable $error) { throw new \RuntimeException('Fixture price Release '.basename($error->getFile()).':'.$error->getLine().' '.$error->getMessage(), previous: $error); }
    if ($phase === 'after capacity deferral') {
        $reset();
        $readSlot = null;
        $limiter = Runtime::limiter();
        $atClaim = static function () use ($limiter, $binding, &$readSlot): void {
            $readSlot = $limiter->acquire($binding, RequestOrigin::Interactive);
        };
        try {
            $runOrder();
            same(['queued', 'write_rate_limited', 0], [$order->fresh()->state, $order->fresh()->safe_code, $purchases()],
                'An interactive read defers the released worker before a purchase');
        } finally {
            if ($readSlot !== null) { $limiter->release($binding, RequestOrigin::Interactive, $readSlot); }
        }
    }
    $setTime($releaseAt->modify($phase !== 'before seal' ? '+301 seconds' : '+270 seconds'));
    $reset();
    if ($phase === 'before seal') {
        $reads = 0;
        $GLOBALS['enomWire']['during'] = static function (array $form) use (&$reads, $setTime, $releaseAt): void {
            if ($form['Command'] === 'GetBalance' && ++$reads === 2) { $setTime($releaseAt->modify('+277 seconds')); }
        };
    }
    $runOrder();
    $order->refresh();
    $blocked = [$order->state, $order->safe_code, $purchases(), $order->funding['claim'] === $funding];
    $priceResults[$phase] = [$blocked, null];
    if ($blocked !== ['held', 'registrar_price_increased', 0, true]) { continue; }

    $setTime($clock->now()->modify('+31 seconds'));
    try { $releasePrice(); } catch (\Throwable $error) { throw new \RuntimeException('Fixture price Release '.basename($error->getFile()).':'.$error->getLine().' '.$error->getMessage(), previous: $error); }
    $successfulReleaseAt = $clock->now();
    $reset();
    $transferState->name = $name;
    $runOrder();
    $order->refresh();
    $priceResults[$phase][1] = [$order->acquisitionAttemptFacts()?->sendState->value, $purchases(), $order->funding['claim'] === $funding];
    if ($phase === 'after capacity deferral' && $purchases() === 1) {
        $setTime($clock->now()->modify('+361 seconds'));
        // A seal is reconciled against its own bounds, even if a retained staff audit entry becomes unreadable or is absent.
        $policy = $order->getRawOriginal('policy');
        $withoutAudit = clone $order;
        $withoutAudit->policy = array_diff_key($order->policy, ['staff_actions' => true]);
        DB::table('domains_operations')->where('id', $order->id)->update(['policy' => $withoutAudit->getAttributes()['policy']]);
        try {
            $proof = ReconciliationEvidence::attempt($order->fresh(), $order->domain, $binding);
            same($successfulReleaseAt->getTimestamp() + 300, $proof->windowEnd->getTimestamp(),
                'The sealed transfer keeps the Release bounds after worker lease expiry');
        } finally {
            DB::table('domains_operations')->where('id', $order->id)->update(['policy' => $policy]);
        }
    }
}
same(array_fill_keys(['before claim', 'before seal', 'after capacity deferral'], [['held', 'registrar_price_increased', 0, true], ['sent', 1, true]]),
    $priceResults, 'An expired price Release preserves funding for the next audited Release '.json_encode($priceResults, JSON_THROW_ON_ERROR));

$cutoffResults = [];
foreach ($cutoffOrders as $case => $order) {
    $cutoff = TransferInputs::deadline($order, $clock->now());
    $setTime($cutoff->modify($case === 'short budget' ? '-24 seconds' : '-25 seconds'));
    Auth::setUser($staff);
    installedOfferEvidence($binding, $clock, true);
    DB::table('domains_runtime_turns')->where('server_id', $server->id)->update(['finished_at' => $clock->now()->getTimestamp()]);
    $transferState->name = $order->domain->domain;
    $reset();
    if ($case === 'wire refusal') {
        config(['domains.provider_send_guard' => static function ($registrar, $environment, $command) use ($setTime, $cutoff): void {
            if ($command === 'TP_CreateOrder') {
                $setTime($cutoff);
                throw new \RuntimeException('Synthetic refusal before cURL');
            }
        }]);
    }
    try {
        (new RunOperation($order->id))->handle(app(RuntimeWork::class));
    } finally {
        config(['domains.provider_send_guard' => null]);
    }
    $order->refresh();
    $facts = $order->acquisitionAttemptFacts();
    $proof = $facts ? OperationAttempts::testCapacityRefusal($facts) : null;
    $cutoffResults[$case] = [$order->state, $case === 'full budget' ? null : $order->safe_code,
        $purchases(), $facts?->sealed(), $proof !== null];
}
same([
    'full budget' => ['uncertain', null, 1, true, false],
    'short budget' => ['held', 'transfer_setup_review_required', 0, false, false],
    'wire refusal' => ['held', 'transfer_setup_review_required', 0, true, true],
], $cutoffResults, 'Transfer cutoff keeps a full request budget and retains a physical refusal: '.json_encode($cutoffResults, JSON_THROW_ON_ERROR));

$refused = $cutoffOrders['wire refusal']->fresh();
Auth::setUser($staff);
expect(!OperationActions::relevant('release', $refused), 'The expired transfer still cannot be released for purchase');
expect(OperationActions::relevant('refund', $refused), 'Staff can cancel and credit the transfer proved unsent at cutoff');
$credit = static fn (): int => PricingRule::minor((string) (Credit::where('user_id', $user->id)
    ->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$expectedCredit = $credit() + PricingRule::minor($refused->amount);
$ticket = StaffOperationReview::ticket($staff->id, $refused->domain_id, $refused->id, 'refund',
    StaffOperationReview::stamp($refused, $refused->domain), $clock);
(new StaffOperations($clock))->run($staff, $refused->domain_id, $refused->id, 'refund', $ticket, 'Cancel the unsent transfer');
$closed = $refused->fresh();
same(['rejected', 'refund_approved', 'cancelled', $expectedCredit],
    [$closed->state, $closed->safe_code, $closed->domain->service->status, $credit()],
    'Reviewed cancellation closes the transfer and credits its exact payment');
same(null, app(Refunds::class)->creditRejected($closed->id), 'Repeating credit recovery has nothing to return');
same([$expectedCredit, 0], [$credit(), $purchases()], 'Credit recovery leaves the balance intact and sends no purchase');
// A prewire capacity refusal remains returnable even after Release uses its retry admission.
$setTime($clock->now()->modify('+61 seconds'));
$reset();
installedOfferEvidence($binding, $clock, true);
$released = $newPaidTransfer('cutoff released wire refusal');
$cutoff = TransferInputs::deadline($released, $clock->now());
$setTime($cutoff->modify('-120 seconds'));
Auth::setUser($staff);
installedOfferEvidence($binding, $clock, true);
DB::table('domains_runtime_turns')->where('server_id', $server->id)->update(['finished_at' => $clock->now()->getTimestamp()]);
$transferState->name = $released->domain->domain;
$reset();
config(['domains.provider_send_guard' => static function ($registrar, $environment, $command): void {
    if ($command === 'TP_CreateOrder') { throw new \RuntimeException('Synthetic refusal before cURL'); }
}]);
try {
    (new RunOperation($released->id))->handle(app(RuntimeWork::class));
} finally {
    config(['domains.provider_send_guard' => null]);
}
$released->refresh();
same(['held', 'enom_test_transfer_call_cap', 0], [$released->state, $released->safe_code, $purchases()],
    'The sealed capacity refusal arrives before the cutoff without a purchase');
$beforeRelease = $released->acquisitionAttemptFacts();
expect($beforeRelease?->sealed() && OperationAttempts::testCapacityRefusal($beforeRelease) !== null, 'This exact sealed refusal has one retry admission');
$ticket = StaffOperationReview::ticket($staff->id, $released->domain_id, $released->id, 'release',
    StaffOperationReview::stamp($released, $released->domain), $clock);
(new StaffOperations($clock))->run($staff, $released->domain_id, $released->id, 'release', $ticket, 'Release the verified prewire refusal');
$released->refresh();
same('queued', $released->state, 'Release queues the transfer before cutoff');
same(null, OperationAttempts::testCapacityRefusal($released->acquisitionAttemptFacts()), 'Release consumes the capacity retry admission');

$setTime($cutoff);
$reset();
(new RunOperation($released->id))->handle(app(RuntimeWork::class));
$released->refresh();
same(['held', 'transfer_setup_review_required', $beforeRelease->token->attemptId, 0],
    [$released->state, $released->safe_code, $released->acquisitionAttemptFacts()?->token->attemptId, $purchases()],
    'Cutoff holds the same released attempt with no replacement and no send');
same(AcquisitionPhase::ProvedUnsent, $released->acquisitionLifecycle($clock->now())?->phase,
    'Consumed admission leaves the original sealed refusal proved unsent');
expect(!OperationActions::relevant('release', $released), 'Cutoff cannot grant another retry');
expect(OperationActions::relevant('refund', $released), 'The released prewire refusal still offers cancellation and credit');
$expectedCredit = $credit() + PricingRule::minor($released->amount);
$ticket = StaffOperationReview::ticket($staff->id, $released->domain_id, $released->id, 'refund',
    StaffOperationReview::stamp($released, $released->domain), $clock);
(new StaffOperations($clock))->run($staff, $released->domain_id, $released->id, 'refund', $ticket, 'Cancel the released unsent transfer');
$closed = $released->fresh();
same(['rejected', 'refund_approved', 'cancelled', $expectedCredit],
    [$closed->state, $closed->safe_code, $closed->domain->service->status, $credit()],
    'The released refusal returns the original payment');
same(null, app(Refunds::class)->creditRejected($closed->id), 'Recovery cannot return the released attempt twice');
same([$expectedCredit, 0], [$credit(), $purchases()], 'Credit leaves the released balance intact and sends nothing');
$nativeSuccessful = true;
