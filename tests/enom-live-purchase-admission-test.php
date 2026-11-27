<?php
// Native worker admission after a sealed local refusal. The Enom wire is synthetic.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Auth, DB, Queue};
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Jobs\{ExecuteOperation, RunOperation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{FundsWaits, NativeCatalogue, PricingRule, Runtime, RuntimeDispatch, RuntimeWork, StaffOperationReview, StaffOperations};

$liveTransferRuntime = true;
require __DIR__.'/enom-live-transfer-test.php';
$nativeSuccessful = false;

$reset();
$config = 'domains.enom_live.accounts.live-uid.daily_spend_limit_usd';
config(['domains.enom_live.registration' => true, $config => '300.00']);
try {
    $write('activate', ['registration' => true, 'renewal' => true, 'management' => false, 'transfer' => true, 'confirmed' => true]);
    $write('publish', [], $tld->id);
} catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
    throw new RuntimeException('Enom order activation HTTP '.$error->getStatusCode().' at '.basename($error->getTrace()[1]['file'] ?? '').':'.($error->getTrace()[1]['line'] ?? 0), previous: $error);
}
$product = app(NativeCatalogue::class)->product($tld->fresh(), 'register');
expect($product !== null, 'Synthetic Live registration is active');
$setTime = static function (DateTimeImmutable $time) use ($clock): void {
    $clock->at = $time;
    Carbon\Carbon::setTestNow($time);
    Carbon\CarbonImmutable::setTestNow($time);
};
$wire = static function (bool $funded = true) use ($reset, $setupReply): void {
    $reset();
    $GLOBALS['enomWire']['reply'] = static function (array $form) use ($setupReply, $funded): string {
        if ($form['Command'] === 'Purchase') {
            return '<interface-response><Command>Purchase</Command><ErrCount>0</ErrCount><Done>true</Done>'
                .'<RRPCode>200</RRPCode><OrderID>1234</OrderID><ExpirationDate>2027-10-21</ExpirationDate></interface-response>';
        }
        $reply = $setupReply($form);
        return match ($form['Command']) {
            'Check' => str_replace('<RRPCode>211</RRPCode>', '<RRPCode>210</RRPCode>', $reply),
            'GetBalance' => $funded ? $reply : str_replace('<AvailableBalance>100.00</AvailableBalance>', '<AvailableBalance>0.01</AvailableBalance>', $reply),
            default => $reply,
        };
    };
};
$run = static fn (Operation $order) => (new RunOperation($order->id))->handle(app(RuntimeWork::class));
$purchases = static fn (): int => array_count_values(array_column($GLOBALS['enomWire']['forms'], 'Command'))['Purchase'] ?? 0;
$credit = static fn (): int => PricingRule::minor((string) (App\Models\Credit::where('user_id', $user->id)
    ->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$processing = static fn (bool $enabled) => DB::table('domains_accounts')->where('id', $a->id)->update(['processing_enabled' => $enabled]);
$atClaim = $atSeal = null;
$pauseOrders = false;
Operation::updated(static function (Operation $op) use (&$atClaim, &$atSeal): void {
    if ($op->action !== 'register' || $op->state !== 'running') { return; }
    if ($atClaim && $op->isDirty('state')) {
        DB::afterCommit($atClaim);
        $atClaim = null;
    }
    if ($atSeal && $op->isDirty('transition_revision') && $op->acquisitionAttemptFacts()?->sealed()) {
        DB::afterCommit($atSeal);
        $atSeal = null;
    }
});
DB::listen(static function ($query) use (&$pauseOrders, $processing): void {
    if ($pauseOrders && DB::transactionLevel() === 0 && str_starts_with($query->sql, 'select * from "domains_accounts"')) {
        $pauseOrders = false;
        $processing(false);
    }
});

$observed = [];
$day = $clock->now()->modify('+2 days');
foreach (['funded', 'shortfall', 'deferred-live', 'deferred-expired', 'orders-scheduler', 'orders-job', 'orders-expired'] as $index => $case) {
    $setTime($day->modify('+'.(4 * $index).' days'));
    $wire();
    installedOfferEvidence($binding, $clock, true);
    DB::table('domains_runtime_turns')->where('server_id', $server->id)->update(['finished_at' => $clock->now()->getTimestamp()]);
    $cart = commerceCart($user);
    $name = placeholderComFixtureName('live-admission-'.$case.'-placeholder.com');
    commerceAdd($cart, $product, $name);
    try { commerceCheckout($user, $cart)->assertHasNoErrors(); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
        throw new RuntimeException('Enom order checkout HTTP '.$error->getStatusCode().' for '.$case, previous: $error);
    }
    $order = Domain::where('domain', $name)->sole()->operations()->sole();
    App\Helpers\ExtensionHelper::addPayment($order->invoice_id, null, $order->amount);
    $setTime($clock->now()->modify('+31 seconds'));
    installedOfferEvidence($binding, $clock, true);
    // Let the seal commit, then end the job before the transport consumes its grant.
    $atSeal = static fn () => $setTime($clock->now()->modify('+'.ExecuteOperation::EXECUTION_SECONDS.' seconds'));
    $run($order);
    $order->refresh();
    same(['held', 'acquisition_definitely_unsent', 0, null], [$order->state, $order->safe_code, $purchases(),
        $order->acquisitionAttemptFacts()?->grantConsumedAt], 'A completed seal with an unused grant proves local refusal');
    $refused = $order->acquisitionAttemptFacts()->token;
    $funding = $order->funding['claim'];
    // The first claim's 300-second window has ended when staff authorize Release.
    $releaseAt = $clock->now()->modify('+301 seconds');
    $setTime($releaseAt);
    Auth::setUser($staff);
    $ticket = StaffOperationReview::ticket($staff->id, $order->domain_id, $order->id, 'release',
        StaffOperationReview::stamp($order, $order->domain), $clock);
    (new StaffOperations($clock))->run($staff, $order->domain_id, $order->id, 'release', $ticket, 'Retry the proved-unsent order');

    if (in_array($case, ['funded', 'shortfall'], true)) {
        $wire(false);
        $run($order);
        $order->refresh();
        same(['held', 'registrar_balance_insufficient', 0], [$order->state, $order->safe_code, $purchases()],
            'The released worker confirms a real registrar shortfall');
        $firstWait = $clock->now();
        $setTime($firstWait->modify('+899 seconds'));
        $wire($case === 'funded');
        $run($order);
        same(['held', 0], [$order->fresh()->state, $purchases()], 'The earliest retry observes the 900-second pacing');
        $setTime($firstWait->modify('+901 seconds'));
        installedOfferEvidence($binding, $clock, true);
        $run($order);
        $order->refresh();
        if ($case === 'funded') {
            $observed[$case] = [$purchases(), $order->acquisitionAttemptFacts()->token != $refused,
                $order->funding['claim'] === $funding];
        } else {
            $before = $credit();
            $setTime($firstWait->modify('+3 days +1 second'));
            FundsWaits::sweep($a->fresh(), $clock->now());
            FundsWaits::sweep($a->fresh(), $clock->now());
            $observed[$case] = [$order->fresh()->state, $purchases(), $credit() - $before === PricingRule::minor($order->amount)];
        }
        continue;
    }

    $wire();
    if (str_starts_with($case, 'deferred-')) {
        $limiter = Runtime::limiter();
        $readSlot = null;
        $atClaim = static function () use ($limiter, $binding, &$readSlot): void {
            $readSlot = $limiter->acquire($binding, RequestOrigin::Interactive);
        };
        try {
            $run($order);
            $order->refresh();
            same(['queued', 'write_rate_limited', 0, true], [$order->state, $order->safe_code, $purchases(),
                $order->acquisitionAttemptFacts()->released], 'Read deferral retires the released worker before any purchase');
        } finally {
            if ($readSlot !== null) { $limiter->release($binding, RequestOrigin::Interactive, $readSlot); }
        }
    } else {
        $pauseOrders = true;
        $run($order);
        $order->refresh();
        same([false, 'held', 'processing', 0, true], [$pauseOrders, $order->state, $order->safe_code, $purchases(),
            $order->acquisitionAttemptFacts()->token == $refused], 'Orders pause leaves the Release ready and sends nothing');
        $run($order);
        same(0, $purchases(), 'Redelivery sends nothing while Orders is paused');
        $processing(true);
    }
    $prior = $order->acquisitionAttemptFacts()->token;
    $expired = str_ends_with($case, '-expired');
    $setTime($releaseAt->modify($expired ? '+301 seconds' : '+31 seconds'));
    $wire();
    $scheduled = null;
    if ($case === 'orders-scheduler') {
        Queue::fake([RunOperation::class]);
        app(RuntimeDispatch::class)->enqueue($order->id);
        $jobs = Queue::pushed(RunOperation::class, static fn (RunOperation $job) => $job->operationId === $order->id);
        $scheduled = $jobs->count();
        foreach ($jobs as $job) { $job->handle(app(RuntimeWork::class)); }
    } else {
        $run($order);
    }
    $order->refresh();
    $run($order);
    $observed[$case] = $expired
        ? [$order->state, $order->safe_code, $purchases(), $order->acquisitionAttemptFacts()->token == $prior]
        : [$purchases(), $order->acquisitionAttemptFacts()->token != $prior, $order->funding['claim'] === $funding, $scheduled];
}

same([
    'funded' => [1, true, true],
    'shortfall' => ['rejected', 0, true],
    'deferred-live' => [1, true, true, null],
    'deferred-expired' => ['held', 'acquisition_definitely_unsent', 0, true],
    'orders-scheduler' => [1, true, true, 1],
    'orders-job' => [1, true, true, null],
    'orders-expired' => ['held', 'acquisition_definitely_unsent', 0, true],
], $observed, 'Purchase admission and financial outcomes follow the retained authorization '.json_encode($observed, JSON_THROW_ON_ERROR));
$nativeSuccessful = true;
