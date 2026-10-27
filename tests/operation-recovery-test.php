<?php
// Synthetic Enom 444f31c claim/seal regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding = 'example.com';
require __DIR__.'/Support/operation-fixture.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
$createOperation = $new;
$new = static function (string $name, bool $paid = true, bool $setup = true, string $action = 'register', int $term = 1) use ($createOperation) {
    return $createOperation(reservedFixtureName($name), $paid, $setup, $action, $term);
};
use App\Models\{Invoice, InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{AdmittedRegistrarDriver, OperationPreflight};
use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation, Capabilities, Capability, DispatchReadiness, Disposition, DriverResult, Evidence, Money, RequestContext, ResultReason, SendState, Support};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, DomainOperations, InvoiceBinding, OperationClaims};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

// Active duplicate worker cannot claim; expired workers enter reconciliation, including definitely-unsealed ones.
foreach ([false, true] as $seal) {
    $op = $new($seal ? 'stale-sealed.example.com' : 'stale-unsealed.example.com'); [$claimed, $domain, $binding] = $claims->claim($op->id);
    $nativeBeforeDuplicate = nativeFinancialSnapshot($op);
    $assertNoSend($op, 'running');
    same($nativeBeforeDuplicate, nativeFinancialSnapshot($op), 'Active duplicate worker preserves native/financial state');
    if ($seal) { $ready = $preflight->inspect($claimed, $domain, new RequestContext($binding, Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $now->modify('+300 seconds'), new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(10), $clock)); $claims->seal($claimed, $ready, $clock->now()); }
    $clock->instant = $clock->instant->modify('+361 seconds'); $result = $assertNoSend($op, 'uncertain'); same('stale_claim', $result->safe_code, 'Expired worker requires reconciliation');
    $clock->instant = $clock->instant->modify('-361 seconds');
}
// Original workers must classify their own expired claim, whether preflight returns or throws.
foreach ([false, true] as $fail) {
    $op = $new($fail ? 'expired-throw.example.com' : 'expired-return.example.com'); $preflight->fail = $fail;
    $preflight->during = static fn () => $clock->instant = $clock->instant->modify('+361 seconds');
    $result = $assertNoSend($op, 'uncertain'); same('stale_claim', $result->safe_code, 'Original expired worker enters reconciliation');
    same('absent', $result->attempt['seal'], 'Expired worker cannot seal');
    $clock->instant = $clock->instant->modify('-361 seconds'); $preflight->during = null; $preflight->fail = false;
}
$op = $new('lease-short.example.com'); $preflight->during = static fn () => $clock->instant = $clock->instant->modify('+276 seconds');
$assertNoSend($op); $clock->instant = $clock->instant->modify('-276 seconds'); $preflight->during = null;
// Frozen source dispatch-CAS scenarios: changed claim identity/state must defeat the old worker.
foreach (['held', 'replacement'] as $change) {
    $op = $new('claim-race-'.$change.'.example.com'); $before = $writer->calls;
    $preflight->during = static function ($claimed) use ($change): void {
        if ($change === 'held') { $claimed->fresh()->update(['state' => 'held', 'lease_expires_at' => null]); }
        else {
            $a = $claimed->attempt; $a['claim_key'] = str_repeat('f', 32);
            DB::table('domains_operations')->where('id', $claimed->id)->update(['attempt' => Illuminate\Support\Facades\Crypt::encryptString(json_encode($a)), 'started_at' => '2026-10-18 12:00:01']);
        }
    };
    $result = $engine->execute($op->id); same($before, $writer->calls, 'Old claim cannot dispatch after concurrent replacement');
    same($change === 'held' ? 'held' : 'running', $result->state, 'Concurrent worker state preserved'); $preflight->during = null;
}
// A failed seal write rolls back the marker and permits no send.
$op = $new('seal-rollback.example.com'); $failSeal = true;
Operation::updating(static function ($current) use ($op, &$failSeal): void {
    if ($failSeal && $current->id === $op->id && ($current->attempt['seal'] ?? null) === 'sealed') {
        $failSeal = false; throw new RuntimeException('Synthetic seal persistence failure');
    }
});
$result = $assertNoSend($op); expect(!$failSeal, 'Seal persistence fault injected'); same('absent', $result->attempt['seal'], 'Rolled-back seal remains absent');
$op = $new('prior-uncertain.example.com');
Operation::create(['domain_id' => $op->domain_id, 'service_id' => $op->service_id, 'server_id' => $op->server_id,
    'registrar_key' => $op->registrar_key, 'account_fingerprint' => $op->account_fingerprint, 'authority_generation' => 1,
    'operation_key' => 'prior-uncertain', 'action' => 'renew', 'term' => 1, 'currency' => 'USD', 'state' => 'uncertain']);
$assertNoSend($op);
// Leave a complete request plus 30 seconds for evidence persistence before the worker alarm.
foreach ([175, 244, 245] as $elapsed) {
    $op = $new('slow-'.$elapsed.'.example.com'); $before = $writer->calls;
    $preflight->during = static fn () => $clock->instant = $clock->instant->modify('+'.$elapsed.' seconds');
    same('uncertain', $engine->execute($op->id)->state, 'Bounded slow preflight retains dispatch lease');
    same($before + 1, $writer->calls, 'Full request boundary permits one send: '.$elapsed);
    same($now->modify('+'.($elapsed + 25).' seconds')->format(DATE_ATOM), $writer->context->deadline->format(DATE_ATOM), 'Dispatch deadline leaves the finish margin');
    $clock->instant = $clock->instant->modify('-'.$elapsed.' seconds'); $preflight->during = null;
}
// Refusing the last request leaves an unsealed, recoverable operation with its funding intact.
foreach ([246, 275] as $elapsed) {
    $op = $new('finish-margin-'.$elapsed.'.example.com'); $money = $nativeMoney();
    $preflight->during = static fn () => $clock->instant = $clock->instant->modify('+'.$elapsed.' seconds');
    $result = $assertNoSend($op);
    same('seal_authority_invalid', $result->safe_code, 'Finish headroom refusal uses the safe seal hold');
    same('absent', $result->attempt['seal'], 'Deadline refusal cannot seal');
    same('definitely_unsent', $result->attempt['send_state'], 'Deadline refusal is recoverable without send evidence');
    expect(!array_key_exists('execution', $result->attempt), 'Unsent deadline refusal records no execution');
    same($money, $nativeMoney(), 'Deadline refusal preserves native funding');
    $clock->instant = $clock->instant->modify('-'.$elapsed.' seconds'); $preflight->during = null;
}
// A second physical preflight read after entry +270 must be stopped by the shared context.
$op = $new('preflight-deadline.example.com'); $entry = $clock->now(); $before = $writer->calls; $readStopped = false;
$preflight->during = static function ($op, $domain, $context) use ($clock, $entry, &$readStopped): void {
    $clock->instant = $entry->modify('+271 seconds');
    try { $context->claimRead($context->binding); }
    catch (RuntimeException) { $readStopped = true; throw new RuntimeException('Synthetic preflight deadline'); }
};
$engine->execute($op->id);
same(true, $readStopped, 'Preflight requests share the worker finish cap');
same($before, $writer->calls, 'Exhausted preflight cannot dispatch');
same('absent', $op->fresh()->attempt['seal'], 'Exhausted preflight stays unsealed');
$clock->instant = $entry; $preflight->during = null;
// Simulate waiting on the claim's first locking read, before started_at is sampled.
$waiting = false; $wait = 0;
DB::listen(static function ($query) use (&$waiting, $clock, &$wait): void {
        if ($waiting && str_contains($query->sql, '"users"')) {
            $waiting = false; $clock->instant = $clock->instant->modify('+'.$wait.' seconds');
        }
});
foreach ([44, 46] as $wait) {
    $op = $new('claim-wait-'.$wait.'.example.com'); $entry = $clock->now(); $before = $writer->calls; $waiting = true;
    $preflight->during = static fn () => $clock->instant = $clock->instant->modify('+200 seconds');
    $result = $engine->execute($op->id); $waiting = false;
    same($entry->modify('+'.$wait.' seconds')->format(DATE_ATOM), $result->started_at->format(DATE_ATOM), 'Claim wait precedes durable start');
    same($before + ($wait === 44 ? 1 : 0), $writer->calls, 'Claim lock wait consumes the worker window');
    same($wait === 44 ? 'uncertain' : 'held', $result->state, 'Claim wait keeps the ordinary safe outcome');
    if ($wait === 46) { same('absent', $result->attempt['seal'], 'Claim wait refuses before seal'); }
    $clock->instant = $entry; $preflight->during = null;
}
// Late same-worker facts survive expiry or another worker's stale classification without reopening authority.
foreach (['expired', 'swept', 'replaced'] as $late) {
    $op = $new('late-'.$late.'.example.com'); $before = $writer->calls;
    $writer->during = static function ($saved) use ($clock, $engine, $late): void {
        $clock->instant = $clock->instant->modify('+361 seconds');
        if ($late !== 'expired') { same('uncertain', $engine->execute($saved->id)->state, 'Concurrent worker quarantines stale claim'); }
        if ($late === 'replaced') {
            $a = $saved->attempt; $a['claim_key'] = str_repeat('e', 32);
            DB::table('domains_operations')->where('id', $saved->id)->update(['attempt' => Illuminate\Support\Facades\Crypt::encryptString(json_encode($a))]);
        }
    };
    $result = $engine->execute($op->id); same($before + 1, $writer->calls, 'Late response never resends');
    same('uncertain', $result->state, 'Late response remains uncertain'); same('stale_claim', $result->safe_code, 'Late facts preserve quarantine');
    same($late !== 'replaced', array_key_exists('execution', $result->attempt), 'Only matching worker may retain late evidence');
    if ($late !== 'replaced') { expect(isset($result->attempt['evidence']), 'Late neutral facts retained'); same('unknown', $result->attempt['execution']['disposition'], 'Late normalized result retained'); }
    $clock->instant = $clock->instant->modify('-361 seconds'); $writer->during = null; $assertNoSend($result, 'uncertain');
}
foreach (['consume', 'binding'] as $failure) {
    $op = $new('writer-'.$failure.'.example.com'); $writer->consume = $failure !== 'consume'; $writer->wrongBinding = $failure === 'binding';
    $result = $engine->execute($op->id); same('uncertain', $result->state, 'Invalid bridge result cannot complete');
    expect(array_key_exists('execution', $result->attempt) && $result->attempt['execution'] === null, 'Rejected bridge result stays recorded-null');
    $writer->consume = true; $writer->wrongBinding = false;
}
commerceReject(fn () => clone $engine); commerceReject(fn () => serialize($engine));
// Old bindings survive future seller changes and flags; seller policy is not dispatch authority.
$op = $new('old-seller.example.com'); $sellers->change('example.com', 2, $tlds[1]->server_id, true, true, $now);
$tlds[0]->update(['enabled' => false, 'register_enabled' => false]);
same('uncertain', $engine->execute($op->id)->state, 'Paid prior seller dispatches after switch and sale withdrawal');
echo "Operation expiry, late evidence and one-attempt recovery checks passed\n";
$nativeSuccessful = true;
