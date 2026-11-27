<?php
// Synthetic entry-budget regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/management.php';
require_once __DIR__.'/Support/fixture-cases.php';
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;

// Exercise the real monotonic branch and cleanup independently of the synthetic wait clock. The test sets wall time
// through the framework clock and elapsed time through the clock's source, so a wall step during entry is visible.
$elapsed = 0; $systemClock = new \Paymenter\Extensions\Servers\Domains\Data\SystemClock(static function () use (&$elapsed): int { return $elapsed; });
$entryDeadline = \Paymenter\Extensions\Servers\Domains\Services\EntryDeadline::class;
$framework = Carbon\CarbonImmutable::getTestNow(); $entry = new DateTimeImmutable('2026-10-18T12:00:00Z'); $utc = new DateTimeZone('UTC');
try {
    foreach ([270, 250] as $seconds) {
        Carbon\CarbonImmutable::setTestNow($entry); $elapsed = 0;
        $deadline = $entryDeadline::run($systemClock, static function ($deadline) use ($systemClock, $entry, $utc, &$elapsed) {
            try { $systemClock->withinExecution(static fn () => null); expect(false, 'Entry work must run in monotonic scope'); }
            catch (LogicException $error) { same('Nested execution clock scope.', $error->getMessage(), 'Real execution scope active'); }
            same('UTC', $deadline->getTimezone()->getName(), 'Absolute deadline is UTC');
            // The wall clock steps back an hour while 1.5 seconds pass: the execution clock counts only elapsed time.
            Carbon\CarbonImmutable::setTestNow($entry->modify('-1 hour')); $elapsed = 1500000000;
            same($entry->modify('+1500 milliseconds')->format('Y-m-d\\TH:i:s.u'), $systemClock->now()->setTimezone($utc)->format('Y-m-d\\TH:i:s.u'),
                'Execution clock follows elapsed time across a wall step');
            return $deadline;
        }, $seconds);
        same($entry->modify('+'.$seconds.' seconds')->format('Y-m-d\\TH:i:s.u'), $deadline->format('Y-m-d\\TH:i:s.u'), 'Deadline is the entry instant plus the explicit budget');
        same($entry->modify('-1 hour')->format(DATE_ATOM), $systemClock->now()->setTimezone($utc)->format(DATE_ATOM), 'Clock returns to wall time after entry');
    }
} finally { Carbon\CarbonImmutable::setTestNow($framework); }
try { $entryDeadline::run($systemClock, static fn () => throw new RuntimeException('synthetic scope failure')); }
catch (RuntimeException $error) { same('synthetic scope failure', $error->getMessage(), 'Entry exception propagates'); }
same('released', $entryDeadline::run($systemClock, static fn () => 'released'), 'Exception releases execution scope');
// With no FakeClock bound, the after-boot framework clock is wall time, and its UTC instant stays wall time under a
// non-UTC site zone, so the execution origin is not shifted by the zone offset.
$clockContract = \Paymenter\Extensions\Servers\Domains\Contracts\Clock::class; $priorClock = app($clockContract);
$priorZone = date_default_timezone_get(); $priorNow = Carbon\CarbonImmutable::getTestNow(); $elapsed = 0;
try {
    app()->instance($clockContract, $systemClock); date_default_timezone_set('America/Los_Angeles'); nativeFixtureClockFollow();
    $before = time(); $origin = $systemClock->withinExecution(static fn () => $systemClock->now()); $after = time();
    expect($before <= $origin->getTimestamp() && $origin->getTimestamp() <= $after, 'Wall fallback keeps the UTC execution origin: '.json_encode([$before, $origin->format(DATE_ATOM), $after]));
} finally { date_default_timezone_set($priorZone); app()->instance($clockContract, $priorClock); Carbon\CarbonImmutable::setTestNow($priorNow); }

$waiting = false; $wait = 0;
DB::listen(static function ($query) use (&$waiting, &$wait, $clock): void {
    if ($waiting && str_contains($query->sql, '"users"')) {
        $waiting = false; $clock->instant = $clock->now()->modify('+'.$wait.' seconds');
    }
});
$noSend = static function ($op, array $money) use ($nativeMoney, $state): void {
    $op->refresh(); same('held', $op->state, 'Deadline refusal holds claimed management');
    same('absent', $op->attempt['seal'], 'Deadline refusal precedes seal');
    same('definitely_unsent', $op->attempt['send_state'], 'Deadline refusal preserves unsent proof');
    same(null, $op->provider_order_id, 'No order proof on refusal');
    expect(!isset($op->attempt['management_ack']) && !array_key_exists('execution', $op->attempt), 'No execution acknowledgement on refusal');
    same([], array_values(array_filter(array_column($state->calls, 0), fn ($c) => !str_starts_with($c, 'Get'))), 'No physical write');
    same($money, $nativeMoney(), 'Native finance unchanged on refusal');
};
[$deadlineDomain, $deadlineDriver] = $holding(placeholderComFixtureName('management-boundary-placeholder.com'));
$cases = [];
foreach ([245, 246] as $elapsed) {
    $cases['background-'.$elapsed] = static function () use ($deadlineDomain, $deadlineDriver, $submit, $nativeMoney, $state, $clock, $management, $noSend, $elapsed): void {
        $op = $submit($deadlineDomain, $deadlineDriver, 'lock', ['locked'=>false]); $money = $nativeMoney();
        $state->during = static fn () => $clock->instant = $clock->now()->modify('+'.$elapsed.' seconds');
        $result = $management->run($op->id, $deadlineDriver, RequestOrigin::Background);
        if ($elapsed === 245) { same('succeeded', $result->state, 'Exactly 25 seconds before finish cap admits one write'); }
        else { $noSend($result, $money); }
    };
}
foreach ([44, 46] as $waitSeconds) {
    $cases['claim-wait-'.$waitSeconds] = static function () use ($deadlineDomain, $deadlineDriver, $submit, $nativeMoney, $state, $clock, $management, $noSend, $waitSeconds, &$waiting, &$wait): void {
        $op = $submit($deadlineDomain, $deadlineDriver, 'lock', ['locked'=>false]); $entry = $clock->now(); $money = $nativeMoney();
        $wait = $waitSeconds; $waiting = true;
        $state->during = static fn () => $clock->instant = $clock->now()->modify('+200 seconds');
        try { $result = $management->run($op->id, $deadlineDriver, RequestOrigin::Background); }
        finally { $waiting = false; }
        same($entry->modify('+'.$waitSeconds.' seconds')->format(DATE_ATOM), $result->started_at->format(DATE_ATOM), 'Claim wait precedes durable start');
        if ($waitSeconds === 44) { same('succeeded', $result->state, 'Claim wait plus slow preflight fits'); }
        else { $noSend($result, $money); }
    };
}
foreach ([225000000, 225000001] as $micros) {
    $cases['interactive-'.$micros] = static function () use ($deadlineDomain, $deadlineDriver, $submit, $nativeMoney, $state, $clock, $management, $noSend, $micros): void {
        $op = $submit($deadlineDomain, $deadlineDriver, 'lock', ['locked'=>false]); $money = $nativeMoney();
        $state->during = static fn () => $clock->instant = $clock->now()->modify('+'.$micros.' microseconds');
        $result = $management->run($op->id, $deadlineDriver);
        if ($micros === 225000000) { same('succeeded', $result->state, 'Interactive full-request boundary admitted'); }
        else { $noSend($result, $money); }
    };
}
nativeFixtureCases($cases, compact('clock', 'state', 'deadlineDomain', 'deadlineDriver', 'management'));
// A second physical preparation read cannot acquire a new window.
[$d, $driver] = $holding(placeholderComFixtureName('management-late-preflight-placeholder.com')); $state->privacy = true;
$op = $submit($d, $driver, 'privacy_enable'); $money = $nativeMoney();
$state->during = static fn () => $clock->instant = $clock->now()->modify('+271 seconds');
$noSend($management->run($op->id, $driver, RequestOrigin::Background), $money);
same(['GetDomainInfo'], array_column($state->calls, 0), 'Second preparation wire is stopped by shared cap');

// Paid privacy retains a timely response ID even if its second physical readback is too late.
[$d, $driver] = $holding(placeholderComFixtureName('management-late-readback-placeholder.com')); $state->privacy = false;
$product->server->settings()->updateOrCreate(['key'=>'domains_privacy_price'], ['value'=>'5.00','type'=>'string']);
$op = $submit($d, $driver, 'privacy_purchase');
\App\Helpers\ExtensionHelper::addPayment(Invoice::findOrFail($op->invoice_id), null, $op->amount);
$money = $nativeMoney(); $state->calls = [];
$state->during = static fn () => $clock->instant = $clock->now()->modify('+245 seconds');
$state->afterWrite = static function () use ($clock, $state): void {
    $clock->instant = $clock->now()->modify('+24 seconds');
    $state->during = static fn () => $clock->instant = $clock->now()->modify('+2 seconds');
};
$result = $management->run($op->id, $driver, RequestOrigin::Background);
same('uncertain', $result->state, 'Incomplete late readback never confirms purchase');
same('7001', $result->attempt['management_ack']['order_id'], 'Timely final write response ID retained');
same(['GetDomainInfo','GetWPPSInfo','GetBalance','PurchaseServices','GetDomainInfo'], array_column($state->calls, 0), 'Second physical readback blocked after shared cap');
same($money, $nativeMoney(), 'Paid readback does not change native finance');

// Explicit reconciliation and interactive billing include their own initial lock waits.
$state->calls = []; $wait = 251; $waiting = true;
same('uncertain', $management->reconcile($op->id, $driver)->state, 'Expired reconciliation remains unresolved'); $waiting = false;
same([], $state->calls, 'Reconciliation capture wait exhausts explicit request budget');
[$d, $driver] = $holding(placeholderComFixtureName('management-billing-wait-placeholder.com')); $state->privacy = false;
$money = $nativeMoney(); $waiting = true;
try { $submit($d, $driver, 'privacy_purchase'); expect(false, 'Expired billing must refuse'); }
catch (RuntimeException $error) { same('Registrar request deadline reached.', $error->getMessage(), 'Billing deadline refusal'); }
$waiting = false;
same([], $state->calls, 'Interactive billing capture wait exhausts explicit request budget');
same($money, $nativeMoney(), 'Expired billing request creates no native debt');
$nativeSuccessful = true;
echo "Management entry deadlines passed\n";
