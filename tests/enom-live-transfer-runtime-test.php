<?php
// Native paid runtime and physical transfer guards; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$liveTransferRuntime=true;
require __DIR__.'/enom-live-transfer-test.php';

use Illuminate\Support\Facades\{Auth, DB};
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{ReconciliationEvidence, Runtime, RuntimeWork, StaffOperationReview, StaffOperations, TransferInputs};

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
$priceTransfers = [];
foreach (['before claim', 'before seal', 'after capacity deferral'] as $phase) {
    $cart = commerceCart($user);
    $domainName = placeholderComFixtureName('live-expired-'.str_replace(' ', '-', $phase).'-placeholder.com');
    commerceAdd($cart, $transferProduct, $domainName);
    commerceCheckout($user, $cart)->assertHasNoErrors();
    $expired = Domain::where('domain', $domainName)->sole()->operations()->sole();
    App\Helpers\ExtensionHelper::addPayment($expired->invoice_id, null, $expired->amount);
    $request = Illuminate\Http\Request::create('/code', 'POST', ['transfer_code' => 'SyntheticOnly']);
    $request->setUserResolver(fn () => $user);
    app(TransferInputs::class)->submitCode($request, $expired->id);
    $priceTransfers[$phase] = $expired->fresh();
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
$nativeSuccessful = true;
