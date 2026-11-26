<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Money, SendState};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AttemptToken, OperationAttempts, OperationSchema, OperationWriter, ReadToken, SealTerms, StepKey, Transition, WireOutcome};
use Paymenter\Extensions\Servers\Domains\Services\FoundationSchema;

// Registry does not install the operation tables yet; this test installs them beside the foundation.
FoundationSchema::install(); OperationSchema::install();
$clock = new class implements Clock {
    public DateTimeImmutable $instant;
    public function __construct() { $this->instant = new DateTimeImmutable('2026-11-25T12:00:00+00:00'); }
    public function now(): DateTimeImmutable { return $this->instant; }
};
$operation = static fn (string $key): int => (int) DB::table('domains_operations')->insertGetId(['server_id' => 1, 'registrar_key' => 'enom',
    'domain_id' => 1, 'service_id' => 1, 'operation_key' => $key, 'action' => 'renew', 'state' => 'queued', 'currency' => 'USD']);
$writer = new OperationWriter($clock); $step = StepKey::renew();
$in = static fn (Closure $work) => DB::transaction($work);
$later = static fn (int $seconds) => $clock->now()->modify("+$seconds seconds");
$terms = static fn () => new SealTerms(7, str_repeat('a', 64), new Money('8.120000', 'USD'), 'ordinary', $later(300));

$refused = false; try { $writer->claim(1, $step, $later(60)); } catch (LogicException) { $refused = true; }
expect($refused, 'Writes outside the locked transaction are refused');

// A live claim is busy, an expired one is archived as history, and a released claim never comes back.
$id = $operation('writer-claim');
$first = $in(fn () => $writer->claim($id, $step, $later(60)));
expect($first instanceof AttemptToken && $first->ordinal === 1, 'First claim');
same(Transition::Busy, $in(fn () => $writer->claim($id, $step, $later(60))), 'A live pre-seal claim is busy');
$clock->instant = $later(61);
same(Transition::Stale, $in(fn () => $writer->seal($first, $terms())), 'An expired claim cannot seal');
$second = $in(fn () => $writer->claim($id, $step, $later(60)));
$archived = OperationAttempts::attempt($first);
same([2, false, true, false], [$second->ordinal, $archived->current, $archived->released, $archived->sealed()], 'Expired claim stays as released history');
same(Transition::Stale, $in(fn () => $writer->releasePreSeal($first)), 'A replaced claim cannot release its successor');
same(Transition::Applied, $in(fn () => $writer->releasePreSeal($second)), 'Pre-seal release');
same(null, OperationAttempts::current($id, $step), 'No current attempt after release');
$forged = new AttemptToken($id, $second->stepId, $second->attemptId, 2, str_repeat('0', 32));
same(null, OperationAttempts::attempt($forged), 'A token with another claim key finds nothing');

// Seal freezes the terms; the grant is spent once; only a proved local refusal makes the send definitely unsent.
$third = $in(fn () => $writer->claim($id, $step, $later(60)));
same(SendState::DefinitelyUnsent, OperationAttempts::attempt($third)->sendState, 'An unsealed claim is definitely unsent');
same(Transition::Applied, $in(fn () => $writer->seal($third, $terms())), 'Seal');
same(Transition::Stale, $in(fn () => $writer->seal($third, $terms())), 'A seal is set once');
same(Transition::Stale, $in(fn () => $writer->claim($id, $step, $later(60))), 'A sealed step takes no new claim');
$sealed = OperationAttempts::sealed($id, $step);
same([SendState::PossiblySent, 7, '8.120000', 'USD', 'ordinary', null], [$sealed->sendState, $sealed->operationRevision,
    $sealed->authorizedAmount, $sealed->authorizedCurrency, $sealed->mode, $sealed->grantConsumedAt], 'Sealed terms');
same(Transition::Applied, $in(fn () => $writer->consumeGrant($third)), 'Grant consumed');
same(Transition::Stale, $in(fn () => $writer->consumeGrant($third)), 'A grant is consumed once');
$clock->instant = $later(400);
same(Transition::Stale, $in(fn () => $writer->releasePreSeal($third)), 'A sealed claim past its deadline is not released');
same(Transition::Applied, $in(fn () => $writer->recordReply($third, WireOutcome::unknown())), 'Unknown reply recorded');
same(Transition::Stale, $in(fn () => $writer->recordReply($third, WireOutcome::localRefusal('transport'))), 'One reply per attempt');
same([SendState::PossiblySent, 'unknown'], [OperationAttempts::attempt($third)->sendState,
    OperationAttempts::evidence($id, $third->attemptId, 'reply')['outcome']], 'An unknown reply keeps the send possibly sent');

$unsentId = $operation('writer-unsent');
$unsent = $in(fn () => $writer->claim($unsentId, $step, $later(60)));
$in(fn () => $writer->seal($unsent, $terms()));
same(Transition::Applied, $in(fn () => $writer->recordReply($unsent, WireOutcome::localRefusal('final_transport'))), 'Local refusal recorded');
same(SendState::DefinitelyUnsent, OperationAttempts::attempt($unsent)->sendState, 'A proved local refusal is definitely unsent');
same(Transition::Stale, $in(fn () => $writer->consumeGrant($unsent)), 'A refused attempt has no grant to spend');
same(true, OperationAttempts::attempt($unsent)->sealed(), 'The seal survives the refusal');

// The grant is spendable only before the sealed dispatch deadline.
$lateId = $operation('writer-late');
$late = $in(fn () => $writer->claim($lateId, $step, $later(60))); $in(fn () => $writer->seal($late, $terms()));
$clock->instant = $later(300);
same(Transition::Stale, $in(fn () => $writer->consumeGrant($late)), 'A grant at its dispatch deadline is not spent');
same(null, OperationAttempts::attempt($late)->grantConsumedAt, 'A late consumption writes nothing');

// Reads: one live reader, a successor inherits uncertainty, a stale finisher cannot clear or settle.
same(Transition::Stale, $in(fn () => $writer->openRead($second, $later(60))), 'An unsealed attempt has no read');
$read = $in(fn () => $writer->openRead($third, $later(60)));
expect($read instanceof ReadToken && !$read->inheritedUnresolved && $writer->readCurrent($read), 'First read is current');
same(Transition::Busy, $in(fn () => $writer->openRead($third, $later(60))), 'A live read is busy');
$clock->instant = $later(61);
$successor = $in(fn () => $writer->openRead($third, $later(60)));
same([true, false, false], [$successor->inheritedUnresolved, $writer->readCurrent($read), $writer->readCurrent($successor)],
    'A successor of an unclosed read inherits uncertainty and cannot answer');
same(false, $in(fn () => $writer->closeRead($read)), 'A stale finisher cannot clear its successor');
same(Transition::Stale, $in(fn () => $writer->settle($read, 'succeeded', ['order' => 'x'])), 'A stale read cannot settle');
same(Transition::Stale, $in(fn () => $writer->settle($successor, OperationWriter::NOT_SENT, [])), 'Inherited uncertainty cannot settle the send as not sent');
same(Transition::Applied, $in(fn () => $writer->settle($successor, 'succeeded', ['order' => '41'])), 'New positive proof settles through an inherited read');
same(true, $in(fn () => $writer->closeRead($successor)), 'The owner closes its read');
same(true, OperationAttempts::attempt($third)->readUnresolved, 'Inherited uncertainty survives the close');
$hash = hash('sha256', json_encode(OperationAttempts::stamp([$id])));
same(Transition::Applied, $in(fn () => $writer->recordObservation($third, ['kind' => 'order', 'expiry' => '2027-11-25'], $clock->now())),
    'A positive observation is retained without a read claim');
same(Transition::Stale, $in(fn () => $writer->recordObservation($third, ['kind' => 'other'], $clock->now())), 'The first observation is kept');
same(['kind' => 'order', 'expiry' => '2027-11-25'], OperationAttempts::evidence($id, $third->attemptId, 'observation')['evidence'], 'Observation evidence');
same($hash, hash('sha256', json_encode(OperationAttempts::stamp([$id]))), 'Evidence leaves the authority stamp unchanged');

$cleanId = $operation('writer-settle');
$clean = $in(fn () => $writer->claim($cleanId, $step, $later(60))); $in(fn () => $writer->seal($clean, $terms()));
$owner = $in(fn () => $writer->openRead($clean, $later(60)));
same(Transition::Applied, $in(fn () => $writer->settle($owner, OperationWriter::NOT_SENT, ['answer' => 'not_processed'])), 'A current read with no inherited uncertainty settles as not sent');
same(Transition::Stale, $in(fn () => $writer->settle($owner, 'succeeded', ['order' => '43'])), 'Settlement is recorded once');
same(Transition::Stale, $in(fn () => $writer->consumeGrant($clean)), 'A not-sent settlement leaves no grant to spend');
same(null, OperationAttempts::attempt($clean)->grantConsumedAt, 'The refused consumption writes nothing');
same(Transition::Applied, $in(fn () => $writer->recordTerminal($unsentId, 'never_sent_unpaid', ['reason' => 'closed'])), 'Terminal proof');
same(Transition::Stale, $in(fn () => $writer->recordTerminal($unsentId, 'never_sent_unpaid', [])), 'Terminal proof is recorded once');
// Evidence is written only as deep as the reader decodes it.
$nest = static function (int $levels): array { $value = []; for ($i = 1; $i < $levels; $i++) { $value = [$value]; } return $value; };
$deepId = $operation('writer-deep');
$deep = false; try { $in(fn () => $writer->recordTerminal($deepId, 'never_sent_unpaid', $nest(OperationAttempts::EVIDENCE_DEPTH))); } catch (JsonException) { $deep = true; }
expect($deep && OperationAttempts::evidence($deepId, null, 'terminal') === null, 'Evidence deeper than the reader accepts is refused before insert');
same(Transition::Applied, $in(fn () => $writer->recordTerminal($deepId, 'never_sent_unpaid', $nest(OperationAttempts::EVIDENCE_DEPTH - 1))), 'The deepest readable evidence is written');
same($nest(OperationAttempts::EVIDENCE_DEPTH - 1), OperationAttempts::evidence($deepId, null, 'terminal')['evidence'], 'The deepest written evidence reads back');

// Database keys hold across independent sessions, and child rows keep their operation.
$file = tempnam((string) getenv('TMPDIR'), 'operation-writer-');
DB::connection()->statement('VACUUM INTO '.DB::connection()->getPdo()->quote($file));
config(['database.connections.writer_other' => [...config('database.connections.sqlite'), 'database' => $file]]);
config(['database.connections.writer_main' => [...config('database.connections.sqlite'), 'database' => $file]]);
DB::setDefaultConnection('writer_main');
try {
    expect(DB::connection()->getPdo() !== DB::connection('writer_other')->getPdo(), 'Two independent database sessions');
    $raceId = $operation('writer-race');
    $mine = $in(fn () => $writer->claim($raceId, $step, $later(60)));
    DB::setDefaultConnection('writer_other');
    same(Transition::Busy, $in(fn () => $writer->claim($raceId, $step, $later(60))), 'The other session sees the committed claim');
    foreach ([['ordinal' => $mine->ordinal, 'claim_key' => str_repeat('1', 32)], ['ordinal' => 9, 'claim_key' => $mine->claimKey]] as $row) {
        $duplicate = false;
        try { DB::table(OperationSchema::ATTEMPTS)->insert(['step_id' => $mine->stepId, 'claimed_at' => '2026-11-25 12:00:00',
            'claim_until' => '2026-11-25 12:01:00', 'send_state' => 'definitely_unsent', ...$row]); }
        catch (QueryException) { $duplicate = true; }
        expect($duplicate, 'Attempt ordinal and claim key are unique across sessions');
    }
    $kept = false; try { DB::table('domains_operations')->where('id', $raceId)->delete(); } catch (QueryException) { $kept = true; }
    expect($kept, 'An operation with attempts cannot be deleted');
} finally { DB::setDefaultConnection('sqlite'); @unlink($file); }

$nativeSuccessful = true;
echo "Domains operation writer: claims, seals, grants, replies, reads and evidence passed.\n";
