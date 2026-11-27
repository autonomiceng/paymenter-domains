<?php
// Native worker admission after a sealed local refusal. The Enom wire is synthetic.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\{QueueManager, Worker, WorkerOptions};
use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Support\Facades\{Auth, DB, Queue};
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Jobs\{ExecuteOperation, RunOperation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\AcquisitionResult;
use Paymenter\Extensions\Servers\Domains\Services\{FundsWaits, NativeCatalogue, OperationClaims, PricingRule, Runtime, RuntimeDispatch, RuntimeWork, StaffOperationReview, StaffOperations};
use Paymenter\Extensions\Servers\Domains\Staff\Release\HeldRelease;

$liveTransferRuntime = true;
require __DIR__.'/enom-live-transfer-test.php';
$nativeSuccessful = false;

$reset();
$config = 'domains.enom_live.accounts.live-uid.daily_spend_limit_usd';
config(['domains.enom_live.registration' => true, $config => '300.00']);
$write('activate', ['registration' => true, 'renewal' => true, 'management' => false, 'transfer' => true, 'confirmed' => true]);
$write('publish', [], $tld->id);
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
$afterFunds = null;
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
DB::listen(static function ($query) use (&$pauseOrders, &$afterFunds, $processing): void {
    if ($pauseOrders && DB::transactionLevel() === 0 && str_starts_with($query->sql, 'select * from "domains_accounts"')) {
        $pauseOrders = false;
        $processing(false);
    }
    if ($afterFunds && str_starts_with($query->sql, 'update "domains_acquisition_results"')
        && str_contains($query->sql, '"confirmed_shortfall_at"')) {
        DB::afterCommit($afterFunds);
        $afterFunds = null;
    }
});

$resumeThroughQueue = static function (Operation $order, DateTimeImmutable $at) use ($clock, $setTime, $wire, $processing, $purchases): array {
    $previous = Queue::getFacadeRoot();
    $queue = new QueueManager(app());
    $queue->addConnector('database', static fn () => new DatabaseConnector(app('db')));
    $worker = new Worker($queue, app('events'), app(ExceptionHandler::class), static fn () => false);
    $options = new WorkerOptions(sleep: 0, maxTries: 1);
    $name = 'purchase-admission-witness';
    $default = config('queue.default');
    $defaultName = config('queue.connections.database.queue');
    config(['queue.default' => 'database', 'queue.connections.database.queue' => $name]);
    $jobs = DB::table('jobs')->where('queue', $name);
    Queue::swap($queue);
    try {
        $setTime($at);
        $wire();
        $window = AcquisitionResult::window($order);
        DB::transaction(static function () use ($order, $jobs): void {
            app(RuntimeDispatch::class)->enqueue($order->id);
            app(RuntimeDispatch::class)->enqueue($order->id);
            same(0, $jobs->count(), 'No wake is published before the transaction commits');
        });
        $published = $jobs->count();
        $processing(false);
        $worker->runNextJob('database', $name, $options);
        $paused = [$jobs->count(), $order->fresh()->state, $order->fresh()->safe_code, $purchases()];
        $processing(true);
        $setTime($clock->now()->modify('+10 seconds'));
        app(RuntimeDispatch::class)->enqueue($order->id);
        $resumed = $jobs->count();
        $worker->runNextJob('database', $name, $options);
        $sealed = $order->fresh()->acquisitionSealWindow();
        return [$published, $paused, $resumed, $purchases(),
            $window !== null && $sealed === $window->seal(false)];
    } finally {
        Queue::swap($previous);
        config(['queue.default' => $default, 'queue.connections.database.queue' => $defaultName]);
    }
};

$observed = [];
$day = $clock->now()->modify('+2 days');
foreach (['funded', 'shortfall', 'deferred-live', 'deferred-expired', 'orders-scheduler', 'orders-job', 'orders-expired',
    'balance-resume-funded', 'balance-resume-short', 'balance-deadline-paused',
    'balance-response-at-cutoff', 'balance-timely-local-refusal', 'balance-real-wake'] as $index => $case) {
    $setTime($day->modify('+'.(4 * $index).' days'));
    $wire();
    Auth::setUser($staff);
    installedOfferEvidence($binding, $clock, true);
    DB::table('domains_runtime_turns')->where('server_id', $server->id)->update(['finished_at' => $clock->now()->getTimestamp()]);
    $cart = commerceCart($user);
    $name = placeholderComFixtureName('live-admission-'.$case.'-placeholder.com');
    commerceAdd($cart, $product, $name);
    commerceCheckout($user, $cart)->assertHasNoErrors();
    $order = Domain::where('domain', $name)->sole()->operations()->sole();
    App\Helpers\ExtensionHelper::addPayment($order->invoice_id, null, $order->amount);
    $setTime($clock->now()->modify('+31 seconds'));
    Auth::setUser($staff);
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

    if (str_starts_with($case, 'balance-')) {
        $wire(false);
        $run($order);
        $order->refresh();
        same(['held', 'registrar_balance_insufficient', 0], [$order->state, $order->safe_code, $purchases()],
            'The installed worker confirms the first shortfall before the Orders pause');
        $firstWait = $clock->now();
        $deadline = $firstWait->modify('+3 days');
        Auth::setUser($staff);
        $offered = HeldRelease::offered($order, $firstWait);
        $review = StaffOperationReview::ticket($staff->id, $order->domain_id, $order->id, 'release',
            StaffOperationReview::stamp($order, $order->domain), $clock);
        $refusedRelease = false;
        try {
            (new StaffOperations($clock))->run($staff, $order->domain_id, $order->id, 'release', $review, 'Release an automatic wait');
        } catch (DomainException) {
            $refusedRelease = true;
        }
        $lateResponse = $case === 'balance-response-at-cutoff';
        $localRefusal = $case === 'balance-timely-local-refusal';
        $queuedWake = $case === 'balance-real-wake';
        $setTime($deadline->modify($queuedWake ? '-5 minutes' : '-120 seconds'));
        Auth::setUser($staff);
        installedOfferEvidence($binding, $clock, true);
        expect(app(OperationClaims::class)->readmitBalance($order->id), 'The last automatic wait admits one replacement before day three');
        if ($queuedWake) {
            $observed[$case] = $resumeThroughQueue($order->fresh(), $clock->now()->modify('+245 seconds'));
            continue;
        }
        if ($lateResponse || $localRefusal) {
            $before = $credit();
            $setTime($deadline->modify('-5 seconds'));
            $wire();
            if ($lateResponse) {
                $GLOBALS['enomWire']['during'] = static function (array $form) use ($setTime, $deadline): void {
                    if ($form['Command'] === 'GetBalance') { $setTime($deadline); }
                };
            } else {
                $afterFunds = static function () use ($processing, $setTime, $deadline): void {
                    $processing(false);
                    $setTime($deadline);
                };
            }
            $run($order);
            $setTime($deadline);
            FundsWaits::sweep($a->fresh(), $clock->now());
            FundsWaits::sweep($a->fresh(), $clock->now());
            $expectedCredit = $lateResponse ? PricingRule::minor($order->amount) : 0;
            $current = $order->fresh();
            $observed[$case] = [$purchases(), $current->state, $current->safe_code, $credit() - $before === $expectedCredit,
                $afterFunds === null];
            $afterFunds = null;
            $processing(true);
            continue;
        }
        $processing(false);
        $wire(false);
        $run($order);
        same(['held', 'processing', 0], [$order->fresh()->state, $order->fresh()->safe_code, $purchases()],
            'A delivered job observes Orders off before the replacement claim');
        $before = $credit();
        $scheduled = null;
        if ($case !== 'balance-deadline-paused') {
            $processing(true);
            $setTime($deadline->modify('-90 seconds'));
            $wire($case === 'balance-resume-funded');
            if ($case === 'balance-resume-funded') {
                DB::table('domains_runtime_operations')->updateOrInsert(['operation_id' => $order->id],
                    ['due_at' => $deadline->modify('+30 minutes')->getTimestamp()]);
                Queue::fake([RunOperation::class]);
                app(RuntimeDispatch::class)->enqueue($order->id);
                $wakes = Queue::pushed(RunOperation::class, static fn (RunOperation $job) => $job->operationId === $order->id);
                $scheduled = $wakes->count();
                foreach ($wakes as $job) { $job->handle(app(RuntimeWork::class)); }
            } else {
                $run($order);
            }
        }
        $setTime($deadline);
        FundsWaits::sweep($a->fresh(), $clock->now());
        FundsWaits::sweep($a->fresh(), $clock->now());
        $funded = $case === 'balance-resume-funded';
        $observed[$case] = [$offered, $refusedRelease, $scheduled, $purchases(),
            $funded ? in_array($order->fresh()->state, ['pending', 'succeeded'], true) : $order->fresh()->state,
            $credit() - $before === ($funded ? 0 : PricingRule::minor($order->amount))];
        $processing(true);
        continue;
    }

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
        Auth::setUser($staff);
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
    'balance-resume-funded' => [false, true, 1, 1, true, true],
    'balance-resume-short' => [false, true, null, 0, 'rejected', true],
    'balance-deadline-paused' => [false, true, null, 0, 'rejected', true],
    'balance-response-at-cutoff' => [0, 'rejected', 'registrar_balance_insufficient', true, true],
    'balance-timely-local-refusal' => [0, 'held', 'processing', true, true],
    'balance-real-wake' => [1, [0, 'held', 'processing', 0], 1, 1, true],
], $observed, 'Purchase admission and financial outcomes follow the retained authorization '.json_encode($observed, JSON_THROW_ON_ERROR));
$nativeSuccessful = true;
