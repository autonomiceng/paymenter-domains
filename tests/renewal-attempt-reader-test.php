<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{ExecutionRecord, SendState};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalAttempt;
use Paymenter\Extensions\Servers\Domains\Services\{FoundationSchema, WriteMoney};

// The renewal reader returns the facts of the retained attempt record in the shapes its writers save.
FoundationSchema::install();
$at = '2026-11-25T04:00:00-08:00'; $start = new DateTimeImmutable('2026-11-25T12:00:00Z'); $done = new DateTimeImmutable('2026-11-25T12:05:00Z');
$sealed = ['claim_key' => str_repeat('c', 32), 'seal' => 'sealed', 'dispatch_attempted_at' => $at, 'send_state' => 'possibly_sent',
    'authorized_cost' => ['amount' => '8.12', 'currency' => 'USD'], 'renewal_mode' => 'ordinary'];
$refusal = ['disposition' => 'rejected', 'send_state' => 'definitely_unsent', 'reason' => 'unsent', 'discrepancies' => []];
$accepted = ['disposition' => 'accepted', 'send_state' => 'sent', 'reason' => 'accepted', 'discrepancies' => []];
$proof = ['order_reference' => '1234', 'charge' => ['amount' => '8.12', 'currency' => 'USD'], 'expiry' => '2027-11-25', 'source' => 'captured_order'];
$n = 0;
// The rows hold only the saved attempt record; binding and transition guards have their own tests.
$make = static function (array $row) use (&$n): int {
    $n++;
    return (int) Operation::withoutEvents(static fn () => Operation::create([...['domain_id' => $n, 'service_id' => $n, 'server_id' => 1, 'registrar_key' => 'enom', 'account_fingerprint' => str_repeat('f', 64),
        'authority_generation' => 1, 'action' => 'renew', 'term' => 1, 'operation_key' => 'reader:'.$n, 'funding_basis' => 'paid_invoice',
        'state' => 'queued', 'amount' => '8.95', 'currency' => 'USD', 'payload' => [], 'policy' => []], ...$row]))->id;
};
$never = $make([]);
$open = $make(['state' => 'running', 'started_at' => $start, 'request_fingerprint' => str_repeat('d', 64), 'attempt' => $sealed]);
$refused = $make(['state' => 'held', 'safe_code' => 'renewal_definitely_unsent', 'started_at' => $start, 'attempt' => [...$sealed, 'execution' => $refusal]]);
$settled = $make(['state' => 'succeeded', 'started_at' => $start, 'completed_at' => $done,
    'attempt' => [...$sealed, 'execution' => $accepted, 'renewal' => ['query' => ['name' => 'example.com', 'years' => 1], 'order_reference' => '1234'], 'reconciliation' => $proof],
    'renewal_proof' => ['claim_key' => $sealed['claim_key'], 'identity' => 'i', 'at' => $at, 'observation' => ['expiry' => '2027-11-25']]]);
$lost = $make(['state' => 'pending', 'started_at' => $start, 'attempt' => [...$sealed, 'execution' => null, 'send_failure' => ['phase' => 'reply']]]);
$review = $make(['state' => 'uncertain', 'safe_code' => 'actual_charge_changed', 'started_at' => $start,
    'attempt' => [...$sealed, 'execution' => $accepted, 'reconciliation' => $proof]]);
$terminal = ['operation_key' => 'reader:7', 'generation' => 1, 'invoice_id' => 9, 'transactions' => []];
$unpaid = $make(['state' => 'rejected', 'safe_code' => 'renewal_cancelled_unpaid', 'completed_at' => $done,
    'attempt' => ['seal' => 'absent', 'send_state' => 'definitely_unsent', 'disposition' => 'rejected', 'rejection_basis' => 'definitely_unsent', 'terminal' => $terminal]]);
$register = $make(['action' => 'register', 'state' => 'running', 'started_at' => $start, 'attempt' => $sealed]);

DB::enableQueryLog(); DB::flushQueryLog();
$facts = RenewalAttempt::many([$never, $open, $refused, $settled, $lost, $review, $unpaid, $register, 999]);
same(1, count(DB::getQueryLog()), 'One read gives every renewal its facts'); DB::disableQueryLog();
same([$never, $open, $refused, $settled, $lost, $review, $unpaid, $register, 999], array_keys($facts), 'Every given id has facts');

$f = $facts[$never];
same([false, null, null, null, null, false, null, null, null, null, null, null, false], [$f->sealed(), $f->sentAt(), $f->sendState(), $f->authorizedCost(), $f->mode(),
    $f->locallyRefused(), $f->replyOutcome(), $f->settlement(), $f->terminal(), $f->claimKey(), $f->orderReference(), $f->windowStart(), $f->observed()], 'A never-sealed renewal has no send facts');

$f = $facts[$open]; $cost = $f->authorizedCost();
same([true, SendState::PossiblySent, 'ordinary', 'USD', false, null, null, str_repeat('c', 32), null, false], [$f->sealed(), $f->sendState(), $f->mode(), $cost->currency,
    $f->locallyRefused(), $f->replyOutcome(), $f->settlement(), $f->claimKey(), $f->orderReference(), $f->observed()], 'A sealed renewal without a reply is possibly sent');
expect(WriteMoney::compare($cost->amount, '8.12') === 0, 'Authorized cost');
same(['2026-11-25T12:00:00+00:00', 'UTC'], [$f->sentAt()->format(DATE_ATOM), $f->sentAt()->getTimezone()->getName()], 'Seal time in UTC');
// Proofs and identities save the window start as recorded, so it keeps the stored zone.
same($at, $f->windowStart()->format(DATE_ATOM), 'The read window starts at the seal time as recorded');
same([$start->modify('+'.ExecuteOperation::EXECUTION_SECONDS.' seconds')->format(DATE_ATOM), 'UTC'], [$f->windowEnd()->format(DATE_ATOM), $f->windowEnd()->getTimezone()->getName()], 'The read window ends after the execution limit, in UTC');
same([ExecutionRecord::Absent, null, null, null], [$f->replyRecord(), $f->reply(), $f->acceptance(), $f->readProof()], 'No reply, acceptance or proof yet');
same([$start->getTimestamp(), str_repeat('d', 64), null, null], [$f->claimedAt()->getTimestamp(), $f->requestDigest(), $facts[$never]->claimedAt(), $facts[$settled]->requestDigest()], 'The sealed attempt claim time and request digest');

$f = $facts[$refused];
same([true, SendState::DefinitelyUnsent, 'unsent', null], [$f->locallyRefused(), $f->sendState(), $f->replyOutcome(), $f->settlement()], 'A prewire refusal is the local refusal');
same([ExecutionRecord::Recorded, $refusal], [$f->replyRecord(), $f->reply()], 'The refusal reply as saved');

$f = $facts[$settled];
same([false, SendState::Sent, 'accepted', '1234', true], [$f->locallyRefused(), $f->sendState(), $f->replyOutcome(), $f->orderReference(), $f->observed()], 'An accepted reply with its order and observation');
same(['outcome' => 'succeeded', 'evidence' => $proof, 'recorded_at' => '2026-11-25 12:05:00'], $f->settlement(), 'A confirmed renewal settled on its proof');
same([$accepted, ['query' => ['name' => 'example.com', 'years' => 1], 'order_reference' => '1234'], $proof], [$f->reply(), $f->acceptance(), $f->readProof()], 'The accepted reply, its acceptance and the read proof');

$f = $facts[$lost];
same([false, SendState::PossiblySent, 'unknown', null], [$f->locallyRefused(), $f->sendState(), $f->replyOutcome(), $f->settlement()], 'A lost reply stays possibly sent');
same([ExecutionRecord::RecordedNull, null], [$f->replyRecord(), $f->reply()], 'A lost reply is recorded as null');
same([null, $proof], [$facts[$review]->settlement(), $facts[$review]->readProof()], 'Captured proof under charge review is read proof, not a settlement');

$f = $facts[$unpaid];
same([false, null, false], [$f->sealed(), $f->sendState(), $f->locallyRefused()], 'An unpaid closure was never sealed');
same(['outcome' => 'renewal_cancelled_unpaid', 'evidence' => $terminal, 'recorded_at' => '2026-11-25 12:05:00'], $f->terminal(), 'Operation-level terminal proof');
same([true, true, false], [$f->closedUnsent(), $f->closedOnlyWith($terminal), $f->closedOnlyWith([...$terminal, 'transactions' => [1]])], 'A local closure before any seal, with its exact terminal evidence');
same([true, false, false, false], [$facts[$never]->untouched(), $f->untouched(), $facts[$open]->untouched(), $facts[$open]->closedUnsent()], 'Only a renewal with nothing recorded is untouched; a sealed one is no closure');
same([false, true, true, false, false], [$facts[$never]->provedUnsent(), $f->provedUnsent(), $facts[$refused]->provedUnsent(), $facts[$open]->provedUnsent(), $facts[$lost]->provedUnsent()],
    'Proved unsent: a closure before any seal or a local refusal of the sealed send; a sealed or lost send is not');

$malformed = $make(['state' => 'held', 'attempt' => ['seal' => 'pending']]);
same([true, true, false, false, false, false], [$facts[$never]->unsealed(), $facts[$unpaid]->unsealed(), $facts[$open]->unsealed(), $facts[$lost]->unsealed(),
    RenewalAttempt::of(Operation::findOrFail($malformed))->unsealed(), RenewalAttempt::of(Operation::findOrFail($malformed))->sealed()],
    'Unsealed: no seal and no reply recorded; a malformed seal counts as recorded, though it is not a seal');

same([false, null, null], [$facts[$register]->sealed(), $facts[$register]->claimKey(), $facts[999]->sentAt()], 'Other actions and missing rows have no renewal attempt');

DB::enableQueryLog(); DB::flushQueryLog();
$one = RenewalAttempt::of(Operation::findOrFail($settled)); $count = count(DB::getQueryLog()); DB::disableQueryLog();
same(1, $count, 'One loaded renewal reads its facts without another query');
$rows = Operation::whereKey([$settled, $unpaid])->get(); DB::enableQueryLog(); DB::flushQueryLog();
$page = RenewalAttempt::loaded($rows); $count = count(DB::getQueryLog()); DB::disableQueryLog();
same([0, [$settled, $unpaid], true], [$count, array_keys($page), $page[$unpaid]->closedUnsent()], 'A page reads its loaded renewals once, with no further query');
same([$facts[$settled]->settlement(), $facts[$settled]->sentAt()->format(DATE_ATOM), $facts[$settled]->claimKey()], [$one->settlement(), $one->sentAt()->format(DATE_ATOM), $one->claimKey()], 'One renewal reads the same facts');
echo "Renewal attempt reader passed.\n"; $nativeSuccessful = true;
