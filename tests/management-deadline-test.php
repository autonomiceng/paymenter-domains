<?php
// Synthetic entry-budget regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;

// Exercise the real monotonic branch and cleanup independently of the synthetic wait clock.
$systemClock = new \Paymenter\Extensions\Servers\Domains\Data\SystemClock;
$entryDeadline = \Paymenter\Extensions\Servers\Domains\Services\EntryDeadline::class;
foreach ([270, 250] as $seconds) {
    $before = $systemClock->now();
    $deadline = $entryDeadline::run($systemClock, static function ($deadline) use ($systemClock) {
        try { $systemClock->withinExecution(static fn () => null); expect(false, 'Entry work must run in monotonic scope'); }
        catch (LogicException $error) { same('Nested execution clock scope.', $error->getMessage(), 'Real execution scope active'); }
        same('UTC', $deadline->getTimezone()->getName(), 'Absolute deadline is UTC');
        return $deadline;
    }, $seconds);
    expect($deadline >= $before->modify('+'.$seconds.' seconds')
        && $deadline <= $systemClock->now()->modify('+'.$seconds.' seconds'), 'Deadline captures real entry with explicit budget');
}
try { $entryDeadline::run($systemClock, static fn () => throw new RuntimeException('synthetic scope failure')); }
catch (RuntimeException $error) { same('synthetic scope failure', $error->getMessage(), 'Entry exception propagates'); }
same('released', $entryDeadline::run($systemClock, static fn () => 'released'), 'Exception releases execution scope');

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
foreach ([245, 246] as $elapsed) {
    [$d, $driver] = $holding('management-boundary-'.$elapsed.'.com');
    $op = $submit($d, $driver, 'lock', ['locked'=>false]); $money = $nativeMoney();
    $state->during = static fn () => $clock->instant = $clock->now()->modify('+'.$elapsed.' seconds');
    $result = $management->run($op->id, $driver, RequestOrigin::Background);
    if ($elapsed === 245) { same('succeeded', $result->state, 'Exactly 25 seconds before finish cap admits one write'); }
    else { $noSend($result, $money); }
}
foreach ([44, 46] as $wait) {
    [$d, $driver] = $holding('management-wait-'.$wait.'.com');
    $op = $submit($d, $driver, 'lock', ['locked'=>false]); $entry = $clock->now(); $money = $nativeMoney();
    $waiting = true;
    $state->during = static fn () => $clock->instant = $clock->now()->modify('+200 seconds');
    $result = $management->run($op->id, $driver, RequestOrigin::Background); $waiting = false;
    same($entry->modify('+'.$wait.' seconds')->format(DATE_ATOM), $result->started_at->format(DATE_ATOM), 'Claim wait precedes durable start');
    if ($wait === 44) { same('succeeded', $result->state, 'Claim wait plus slow preflight fits'); }
    else { $noSend($result, $money); }
}
// Interactive dispatch has an explicit 250-second entry budget, including subsecond headroom.
foreach ([225000000, 225000001] as $micros) {
    [$d, $driver] = $holding('management-interactive-'.$micros.'.com');
    $op = $submit($d, $driver, 'lock', ['locked'=>false]); $money = $nativeMoney();
    $state->during = static fn () => $clock->instant = $clock->now()->modify('+'.$micros.' microseconds');
    $result = $management->run($op->id, $driver);
    if ($micros === 225000000) { same('succeeded', $result->state, 'Interactive full-request boundary admitted'); }
    else { $noSend($result, $money); }
}
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
