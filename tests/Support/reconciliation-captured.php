<?php
// Enom 444f31c reconciliation-registration/provider-disposition/automatic-refresh; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding = 'example.com';
require __DIR__.'/reconciliation-fixture.php';
require_once __DIR__.'/reserved-fixtures.php';
use Paymenter\Extensions\Servers\Domains\Data\{DateEvidence, Disposition, DriverResult, Evidence, ExpiryPrecision, HistoryAcceptance, RegistrationEvidence, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{HistoryAcceptances, ReconciliationEvidence, RenewalAuthority};
// Unknown/sent with a retained order is distinct from a lost reply. Exercise every full-proof refusal.
$preflight->cost = '8.95';
foreach ($capturedFaults as $fault) {
    [$op, $binding] = $prepare(reservedFixtureName('captured-'.($fault ?: 'complete').'.example.com'), 'captured');
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
}
