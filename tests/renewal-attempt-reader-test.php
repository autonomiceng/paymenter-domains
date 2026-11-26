<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\SupportDetails;
use Paymenter\Extensions\Servers\Domains\Data\{ExecutionRecord, Money, SendState};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{OperationSchema, OperationWriter, SealTerms, StepKey, WireOutcome};
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalAttempt;
use Paymenter\Extensions\Servers\Domains\Services\{FoundationSchema, WriteMoney};

// The renewal reader returns the facts of the sealed attempt and evidence rows in the shapes their writers save.
FoundationSchema::install();
$start = new DateTimeImmutable('2026-11-25T12:00:00Z'); $done = new DateTimeImmutable('2026-11-25T12:05:00Z');
$refusal = ['disposition' => 'rejected', 'send_state' => 'definitely_unsent', 'reason' => 'unsent', 'discrepancies' => []];
$accepted = ['disposition' => 'accepted', 'send_state' => 'sent', 'reason' => 'accepted', 'discrepancies' => []];
$acceptance = ['query' => ['name' => 'example.com', 'years' => 1], 'order_reference' => '1234'];
$proof = ['order_reference' => '1234', 'charge' => ['amount' => '8.12', 'currency' => 'USD'], 'expiry' => '2027-11-25', 'source' => 'captured_order'];
$n = 0;
// The rows hold only the saved attempt; binding and transition guards have their own tests.
$make = static function (array $row) use (&$n): int {
    $n++;
    return (int) Operation::withoutEvents(static fn () => Operation::create([...['domain_id' => $n, 'service_id' => $n, 'server_id' => 1, 'registrar_key' => 'enom', 'account_fingerprint' => str_repeat('f', 64),
        'authority_generation' => 1, 'action' => 'renew', 'term' => 1, 'operation_key' => 'reader:'.$n, 'funding_basis' => 'paid_invoice',
        'state' => 'queued', 'amount' => '8.95', 'currency' => 'USD', 'payload' => [], 'policy' => []], ...$row]))->id;
};
// Seals through the writer, then lets $then record the attempt's reply, read, proof and observations.
$seal = static function (int $id, ?Closure $then = null) use ($start): void {
    DB::transaction(static function () use ($id, $then, $start): void {
        $writer = OperationWriter::at($start); $token = $writer->claim($id, StepKey::renew(), $start->modify('+360 seconds'));
        $writer->seal($token, new SealTerms(1, str_repeat('d', 64), new Money('8.12', 'USD'), 'ordinary', $start->modify('+360 seconds')));
        if ($then) { $then($writer, $token); }
    });
};
$never = $make([]);
$open = $make(['state' => 'running', 'started_at' => $start]); $seal($open);
$refused = $make(['state' => 'held', 'safe_code' => 'renewal_definitely_unsent', 'started_at' => $start]);
$seal($refused, static fn ($w, $t) => $w->recordReply($t, WireOutcome::localRefusal('unsent', ['result' => $refusal])));
$settled = $make(['state' => 'succeeded', 'started_at' => $start, 'completed_at' => $done]);
$seal($settled, static function ($w, $t) use ($accepted, $acceptance, $proof, $start, $done): void {
    $w->consumeGrant($t); $w->recordReply($t, WireOutcome::reply(SendState::Sent, 'accepted', ['result' => $accepted, 'acceptance' => $acceptance]));
    // A positive observation and the settlement are retained on the sealed attempt through its read.
    $w->recordObservation($t, ['identity' => 'i', 'observation' => ['expiry' => '2027-11-25']], $start);
    $read = $w->openRead($t, $start->modify('+600 seconds'));
    OperationWriter::at($done->modify('-4 minutes'))->settle($read, 'succeeded', ['identity' => 'i', 'settlement' => $proof]);
});
$lost = $make(['state' => 'pending', 'started_at' => $start]);
$seal($lost, static fn ($w, $t) => $w->recordReply($t, WireOutcome::unknown(['send_failure' => ['phase' => 'reply']])));
$review = $make(['state' => 'uncertain', 'safe_code' => 'actual_charge_changed', 'started_at' => $start]);
$seal($review, static function ($w, $t) use ($accepted, $proof, $start): void {
    $w->recordReply($t, WireOutcome::reply(SendState::Sent, 'accepted', ['result' => $accepted, 'acceptance' => null]));
    $w->hold($w->openRead($t, $start->modify('+60 seconds')), ['identity' => 'i', 'settlement' => $proof]);
});
$terminal = ['operation_key' => 'reader:7', 'generation' => 1, 'invoice_id' => 9, 'transactions' => []];
$unpaid = $make(['state' => 'rejected', 'safe_code' => 'renewal_cancelled_unpaid', 'completed_at' => $done]);
DB::transaction(static fn () => OperationWriter::at($done)->recordTerminal($unpaid, 'renewal_cancelled_unpaid', $terminal));
// A pre-seal claim that was released stays history only.
$released = $make([]);
DB::transaction(static function () use ($released, $start): void { $w = OperationWriter::at($start); $w->releasePreSeal($w->claim($released, StepKey::renew(), $start->modify('+360 seconds'))); });
$register = $make(['action' => 'register', 'state' => 'running', 'started_at' => $start]);

DB::enableQueryLog(); DB::flushQueryLog();
$facts = RenewalAttempt::many([$never, $open, $refused, $settled, $lost, $review, $unpaid, $released, $register, 999]);
same(3, count(DB::getQueryLog()), 'Three reads give every renewal its facts'); DB::disableQueryLog();
same([$never, $open, $refused, $settled, $lost, $review, $unpaid, $released, $register, 999], array_keys($facts), 'Every given id has facts');

$f = $facts[$never];
same([false, null, null, null, null, false, null, null, null, null, null, null, false], [$f->sealed(), $f->sentAt(), $f->sendState(), $f->authorizedCost(), $f->mode(),
    $f->locallyRefused(), $f->replyOutcome(), $f->settlement(), $f->terminal(), $f->claimKey(), $f->orderReference(), $f->windowStart(), $f->observed()], 'A never-sealed renewal has no send facts');

$f = $facts[$open]; $cost = $f->authorizedCost();
same([true, SendState::PossiblySent, 'ordinary', 'USD', false, null, null, null, false], [$f->sealed(), $f->sendState(), $f->mode(), $cost->currency,
    $f->locallyRefused(), $f->replyOutcome(), $f->settlement(), $f->orderReference(), $f->observed()], 'A sealed renewal without a reply is possibly sent');
expect(is_string($f->claimKey()) && preg_match('/^[a-f0-9]{32}$/D', $f->claimKey()) === 1, 'The claim key is the sealed attempt row identity');
expect(WriteMoney::compare($cost->amount, '8.12') === 0, 'Authorized cost');
same(['2026-11-25T12:00:00+00:00', 'UTC'], [$f->sentAt()->format(DATE_ATOM), $f->sentAt()->getTimezone()->getName()], 'Seal time in UTC');
same($start->getTimestamp(), $f->windowStart()->getTimestamp(), 'The read window starts at the seal time');
same([$start->modify('+'.ExecuteOperation::EXECUTION_SECONDS.' seconds')->format(DATE_ATOM), 'UTC'], [$f->windowEnd()->format(DATE_ATOM), $f->windowEnd()->getTimezone()->getName()], 'The read window ends after the execution limit, in UTC');
same([ExecutionRecord::Absent, null, null, null, null], [$f->replyRecord(), $f->reply(), $f->acceptance(), $f->readProof(), $f->sendFailure()], 'No reply, acceptance or proof yet');
same([$start->getTimestamp(), str_repeat('d', 64), null], [$f->claimedAt()->getTimestamp(), $f->requestDigest(), $facts[$never]->claimedAt()], 'The sealed attempt claim time and request digest');

$f = $facts[$refused];
same([true, SendState::DefinitelyUnsent, 'unsent', null], [$f->locallyRefused(), $f->sendState(), $f->replyOutcome(), $f->settlement()], 'A prewire refusal is the local refusal');
same([ExecutionRecord::Recorded, $refusal], [$f->replyRecord(), $f->reply()], 'The refusal reply as saved');

$f = $facts[$settled];
same([false, SendState::Sent, 'accepted', '1234', true], [$f->locallyRefused(), $f->sendState(), $f->replyOutcome(), $f->orderReference(), $f->observed()], 'An accepted reply with its order and observation');
// Evidence times are UTC instants; compare the instant and offset, not the spelling.
$instant = static fn (array $row): array => [...$row, 'recorded_at' => [($t = new DateTimeImmutable($row['recorded_at'], new DateTimeZone('America/Los_Angeles')))->getTimestamp(), $t->getOffset()]];
same(['outcome' => 'succeeded', 'evidence' => $proof, 'recorded_at' => [strtotime('2026-11-25 12:01:00 UTC'), 0]], $instant($f->settlement()), 'A confirmed renewal settled on its proof');
same([$accepted, $acceptance, $proof, false], [$f->reply(), $f->acceptance(), $f->readProof(), $f->heldForReview()], 'The accepted reply, its acceptance and the read proof');

$f = $facts[$lost];
same([false, SendState::PossiblySent, 'unknown', null], [$f->locallyRefused(), $f->sendState(), $f->replyOutcome(), $f->settlement()], 'A lost reply stays possibly sent');
same([ExecutionRecord::RecordedNull, null, ['phase' => 'reply']], [$f->replyRecord(), $f->reply(), $f->sendFailure()], 'A lost reply is recorded without a result, with its send failure');
same([null, $proof, true, null], [$facts[$review]->settlement(), $facts[$review]->readProof(), $facts[$review]->heldForReview(), $facts[$review]->acceptance()],
    'Captured proof under charge review is read proof, not a settlement; a reply for another query has no acceptance');

$f = $facts[$unpaid];
same([false, null, false], [$f->sealed(), $f->sendState(), $f->locallyRefused()], 'An unpaid closure was never sealed');
same(['outcome' => 'renewal_cancelled_unpaid', 'evidence' => $terminal, 'recorded_at' => [strtotime('2026-11-25 12:05:00 UTC'), 0]], $instant($f->terminal()), 'Operation-level terminal proof');
same([true, true, false], [$f->closedUnsent(), $f->closedOnlyWith($terminal), $f->closedOnlyWith([...$terminal, 'transactions' => [1]])], 'A local closure before any seal, with its exact terminal evidence');
same([true, true, false, false, false], [$facts[$never]->untouched(), $facts[$released]->untouched(), $f->untouched(), $facts[$open]->untouched(), $facts[$open]->closedUnsent()],
    'Only a renewal with nothing sealed or closed is untouched; a released claim is history; a sealed one is no closure');
same([false, true, true, false, false], [$facts[$never]->provedUnsent(), $f->provedUnsent(), $facts[$refused]->provedUnsent(), $facts[$open]->provedUnsent(), $facts[$lost]->provedUnsent()],
    'Proved unsent: a closure before any seal or a local refusal of the sealed send; a sealed or lost send is not');
same([true, true, false, false], [$facts[$never]->unsealed(), $facts[$unpaid]->unsealed(), $facts[$open]->unsealed(), $facts[$lost]->unsealed()], 'Unsealed: no sealed attempt');

same([false, null, null], [$facts[$register]->sealed(), $facts[$register]->claimKey(), $facts[999]->sentAt()], 'Other actions and missing rows have no renewal attempt');
// The identity binds the sealed attempt and its reply; read proof only when asked for.
same([null, $proof], [$facts[$settled]->identity()['proof'], $facts[$settled]->identity(true)['proof']], 'The identity leaves read proof out unless asked');
expect($facts[$settled]->identity() !== $facts[$review]->identity(), 'Two attempts have different identities');

DB::enableQueryLog(); DB::flushQueryLog();
$one = RenewalAttempt::of(Operation::findOrFail($settled)); $count = count(DB::getQueryLog()); DB::disableQueryLog();
same(3, $count, 'One loaded renewal reads its facts in two more reads');
$rows = Operation::whereKey([$settled, $unpaid, $register])->get(); DB::enableQueryLog(); DB::flushQueryLog();
$page = RenewalAttempt::loaded($rows); $count = count(DB::getQueryLog()); DB::disableQueryLog();
same([2, [$settled, $unpaid], true], [$count, array_keys($page), $page[$unpaid]->closedUnsent()], 'A page reads its loaded renewals in two reads');
same([$facts[$settled]->settlement(), $facts[$settled]->sentAt()->format(DATE_ATOM), $facts[$settled]->claimKey()], [$one->settlement(), $one->sentAt()->format(DATE_ATOM), $one->claimKey()], 'One renewal reads the same facts');
// An unreadable renewal between two readable ones is left out of the same two reads; of() refuses it alone.
$reply = DB::table(OperationSchema::EVIDENCE)->where(['operation_id' => $lost, 'kind' => 'reply'])->first();
DB::table(OperationSchema::EVIDENCE)->where('id', $reply->id)->update(['evidence' => 'unreadable-fixture']);
$rows = Operation::whereKey([$settled, $lost, $unpaid])->orderBy('id')->get(); DB::enableQueryLog(); DB::flushQueryLog();
$page = RenewalAttempt::loaded($rows); $count = count(DB::getQueryLog()); DB::disableQueryLog();
same([2, [$settled, $unpaid]], [$count, array_keys($page)], 'Only the unreadable renewal is left out, with no extra read');
try { RenewalAttempt::of(Operation::findOrFail($lost)); $refused = false; } catch (DecryptException) { $refused = true; }
DB::table(OperationSchema::EVIDENCE)->where('id', $reply->id)->update(['evidence' => $reply->evidence]);
expect($refused, 'The unreadable renewal alone refuses its read');

$observation = DB::table(OperationSchema::EVIDENCE)->where(['operation_id' => $settled, 'kind' => 'observation'])->first();
DB::table(OperationSchema::EVIDENCE)->where('id', $observation->id)->update(['evidence' => 'unreadable-fixture']);
$sealedAt = SupportDetails::requestTime(Operation::findOrFail($settled));
$unsealedAt = SupportDetails::requestTime(Operation::findOrFail($never));
DB::table(OperationSchema::EVIDENCE)->where('id', $observation->id)->update(['evidence' => $observation->evidence]);
same([$start->getTimestamp(), 0, null], [$sealedAt?->getTimestamp(), $sealedAt?->getOffset(), $unsealedAt],
    'Support details retain the UTC seal time despite an unreadable observation and leave a missing seal unrecorded');

// The sealed attempt row freezes the renewal's desired state, start and request digest without any attempt JSON.
foreach (['payload' => ['changed' => true], 'started_at' => $done, 'request_fingerprint' => str_repeat('e', 64)] as $column => $value) {
    try { Operation::findOrFail($open)->update([$column => $value]); throw new RuntimeException($column.' changed after the seal'); }
    catch (DomainException $e) { same($column === 'payload' ? 'Sealed desired state is frozen.' : 'Original attempt identity is frozen.', $e->getMessage(), 'A sealed renewal keeps its '.$column); }
}
echo "Renewal attempt reader passed.\n"; $nativeSuccessful = true;
