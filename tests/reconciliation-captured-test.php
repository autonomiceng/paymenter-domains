<?php
// Enom 444f31c reconciliation-registration/provider-disposition/automatic-refresh; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The captured order is an Enom registration; no case reads the second registrar account.
$fixtureRegistrars = ['enom'];
$fixtureEnding = 'example.com';
require __DIR__.'/Support/reconciliation-fixture.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
require_once __DIR__.'/Support/fixture-cases.php';
// Unknown/sent with a retained order is distinct from a lost reply. Every case reads the one stored captured
// order with one full-proof fault; the empty fault is the complete proof. The reset restores rows and fakes.
$preflight->cost = '8.95';
[$captured] = $prepare(reservedFixtureName('captured-order.example.com'), 'captured');
$pinned = [$clock->now(), Carbon\Carbon::getTestNow(), Carbon\CarbonImmutable::getTestNow()];
$pin = static function () use ($clock, $pinned): void { [$clock->instant, $native, $immutable] = $pinned; Carbon\Carbon::setTestNow($native); Carbon\CarbonImmutable::setTestNow($immutable); };
$capturedFaults = ['', 'open', 'failed', 'wrong-id', 'wrong-name', 'wrong-action', 'wrong-years', 'wrong-charge',
    'account', 'authority', 'wrong-expiry', 'multiple', 'owner', 'wrong-currency', 'term', 'window'];
foreach ($capturedFaults as $fault) {
    $cases[$fault === '' ? 'complete' : $fault] = static function () use ($fault, $captured, $pin, $observation, $writer, $reconcile, $engine): void {
        $pin();
        $op = $captured->fresh();
        $original = $op->attempt; $before = $writer->calls;
        $observation->fault = $fault;
        if ($fault === 'window') { $observation->billDate = '10/16/2026 5:00:01 AM'; }
        if ($fault === 'account') { Illuminate\Support\Facades\DB::table('domains_domains')->where('id', $op->domain_id)->update(['account_fingerprint' => str_repeat('a', 64)]); }
        if ($fault === 'authority') { Illuminate\Support\Facades\DB::table('domains_domains')->where('id', $op->domain_id)->update(['authority_generation' => 2]); }
        $result = $reconcile->run($op->id);
        same($fault === '' ? 'succeeded' : 'uncertain', $result->state, 'Captured order requires every original proof: '.$fault);
        same($original, array_diff_key($result->attempt, ['reconciliation' => true]), 'Never rewrite the unknown reply or seal');
        $engine->execute($op->id);
        same($before, $writer->calls, 'Captured order never replays a write: '.$fault);
        if ($fault !== '') { same(null, $result->confirmed_expires_at, 'Mismatch cannot anchor paid term'); }
    };
}
nativeFixtureCases($cases, compact('clock', 'observation', 'writer', 'preflight', 'claims', 'bindings', 'user', 'captured'));
$nativeSuccessful = true; echo "Native captured order reconciliation and refusals passed.\n";
