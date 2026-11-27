<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Money, SendState};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAttempt, AttemptToken, OperationAttempts, OperationSchema, OperationWriter, ReadToken, SealTerms, StepKey, Transition, WireOutcome};
use Paymenter\Extensions\Servers\Domains\Services\FoundationSchema;

// Registry does not install the operation tables yet; this test installs them beside the foundation.
FoundationSchema::install(); OperationSchema::install();
$clock = new class implements Clock {
    public DateTimeImmutable $instant;
    public function __construct() { $this->instant = new DateTimeImmutable('2026-11-25T12:00:00+00:00'); }
    public function now(): DateTimeImmutable { return $this->instant; }
};
$operation = static fn (string $key, string $action = 'renew'): int => (int) DB::table('domains_operations')->insertGetId(['server_id' => 1, 'registrar_key' => 'enom',
    'domain_id' => 1, 'service_id' => 1, 'operation_key' => $key, 'action' => $action, 'state' => 'queued', 'currency' => 'USD']);
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

// A new acquisition needs the retired TEST refusal, but reads its own reply.
$acquisitionId = $operation('writer-capacity-history', 'register');
$acquisition = new StepKey('register', 1);
$previous = $in(fn () => $writer->claim($acquisitionId, $acquisition, $later(60), ['digest' => str_repeat('a', 64)]));
$in(fn () => $writer->seal($previous, $terms()));
$refusal = ['phase' => 'purchase', 'refused_at' => $clock->now()->format(DATE_ATOM),
    'dispatch_attempted_at' => $clock->now()->format(DATE_ATOM), 'authorized_cost' => ['amount' => '8.120000', 'currency' => 'USD']];
same(Transition::Applied, $in(fn () => $writer->refuseTestCapacity($previous, $refusal, false)), 'Local TEST refusal retires the sealed attempt');
$current = $in(fn () => $writer->claim($acquisitionId, $acquisition, $later(60), ['digest' => str_repeat('b', 64)]));
$history = DB::table(OperationSchema::EVIDENCE)->where('attempt_id', $previous->attemptId);
(clone $history)->where('kind', 'reply')->update(['evidence' => 'unreadable']);
$projected = AcquisitionAttempt::of($acquisitionId, 'register')->attempt();
same([$current->claimKey, [$refusal]], [$projected['claim_key'], $projected['test_call_refusals']],
    'An unreadable retired reply leaves the current claim and TEST refusal history readable');
(clone $history)->where('kind', 'test_capacity')->update(['evidence' => 'unreadable']);
$batch = AcquisitionAttempt::many(OperationAttempts::latestAcquisitions([$acquisitionId]));
expect($batch[$acquisitionId] instanceof DecryptException, 'An unreadable retired TEST refusal fails closed because it supplies history');

$snapshotId = $operation('writer-current-claim', 'register');
$snapshotClaim = $in(fn () => $writer->claim($snapshotId, $acquisition, $later(60), ['digest' => str_repeat('c', 64)]));
$connection = DB::connection();
$original = $connection->getQueryGrammar();
// The locking read sees the claim committed after the consistent read's snapshot.
$connection->setQueryGrammar(new class($connection) extends Illuminate\Database\Query\Grammars\SQLiteGrammar {
    public function compileSelect(Illuminate\Database\Query\Builder $query)
    {
        if (!$query->lock && $query->from === OperationSchema::EVIDENCE) {
            $query = (clone $query)->whereRaw('0 = 1');
        }
        return parent::compileSelect($query);
    }
});
try {
    $in(static function () use ($snapshotId, $snapshotClaim): void {
        DB::table('domains_operations')->where('id', $snapshotId)->lockForUpdate()->first();
        $current = AcquisitionAttempt::of($snapshotId, 'register', lock: true);
        same($snapshotClaim->claimKey, $current->attempt()['claim_key'], 'A locked acquisition sees the claim and its evidence together');
    });
} finally { $connection->setQueryGrammar($original); }

$answeredId = $operation('writer-accepted-answer', 'register');
$answered = $in(fn () => $writer->claim($answeredId, $acquisition, $later(60), ['digest' => str_repeat('a', 64)]));
$in(fn () => $writer->seal($answered, $terms()));
same(Transition::Applied, $in(fn () => $writer->recordAcquisitionAnswer($answered, ['outcome' => 'accepted', 'order_reference' => '41'])),
    'The acquisition retains an accepted answer');
same(Transition::Stale, $in(fn () => $writer->recordAcquisitionAnswer($answered, ['outcome' => 'not_processed'])),
    'A retained accepted answer prevents a negative settlement');
same(null, OperationAttempts::evidence($answeredId, $answered->attemptId, 'settlement'), 'The conflicting answer writes no settlement');

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
$doneId = $operation('writer-settled');
$done = $in(fn () => $writer->claim($doneId, $step, $later(60))); $in(fn () => $writer->seal($done, $terms()));
$doneRead = $in(fn () => $writer->openRead($done, $later(60)));
same(Transition::Applied, $in(fn () => $writer->settle($doneRead, 'succeeded', ['order' => '44'])), 'Positive proof settles an unspent grant');
same(Transition::Stale, $in(fn () => $writer->consumeGrant($done)), 'A settled attempt has no grant to spend, so a proven renewal is not sent again');
$spentId = $operation('writer-spent');
$spent = $in(fn () => $writer->claim($spentId, $step, $later(60))); $in(fn () => $writer->seal($spent, $terms()));
$in(fn () => $writer->consumeGrant($spent)); $spentRead = $in(fn () => $writer->openRead($spent, $later(60)));
same(Transition::Stale, $in(fn () => $writer->settle($spentRead, OperationWriter::NOT_SENT, ['answer' => 'not_processed'])),
    'A spent grant cannot be settled as not sent, even through a clean read');
same(null, OperationAttempts::evidence($spentId, $spent->attemptId, 'settlement'), 'The refused settlement writes nothing');
same(Transition::Applied, $in(fn () => $writer->recordTerminal($unsentId, 'never_sent_unpaid', ['reason' => 'closed'])), 'Terminal proof');
same(Transition::Stale, $in(fn () => $writer->recordTerminal($unsentId, 'never_sent_unpaid', [])), 'Terminal proof is recorded once');
// Another writer's row lands after the plain read missed it, as an older snapshot would; the unique key decides.
$racedId = $operation('writer-raced'); $racedKey = hash('sha256', "$racedId|-|terminal"); $armed = true;
DB::connection()->beforeExecuting(static function (string $sql, array $bindings, $db) use (&$armed, $racedId, $racedKey): void {
    if (!$armed || !str_starts_with($sql, 'insert into "'.OperationSchema::EVIDENCE.'"')) { return; }
    $armed = false;
    $db->table(OperationSchema::EVIDENCE)->insert(['operation_id' => $racedId, 'source' => 'local', 'kind' => 'terminal',
        'outcome' => 'other_writer', 'authority_key' => $racedKey, 'evidence' => 'x']);
});
same(Transition::Stale, $in(fn () => $writer->recordTerminal($racedId, 'never_sent_unpaid', [])), 'A duplicate authority key the read missed is stale');
same(['other_writer'], DB::table(OperationSchema::EVIDENCE)->where('authority_key', $racedKey)->pluck('outcome')->all(), 'The first writer\'s row is kept');
$armed = false;
// Evidence is written only as deep as the reader decodes it.
$nest = static function (int $levels): array { $value = []; for ($i = 1; $i < $levels; $i++) { $value = [$value]; } return $value; };
$deepId = $operation('writer-deep');
$deep = false; try { $in(fn () => $writer->recordTerminal($deepId, 'never_sent_unpaid', $nest(OperationAttempts::EVIDENCE_DEPTH))); } catch (JsonException) { $deep = true; }
expect($deep && OperationAttempts::evidence($deepId, null, 'terminal') === null, 'Evidence deeper than the reader accepts is refused before insert');
same(Transition::Applied, $in(fn () => $writer->recordTerminal($deepId, 'never_sent_unpaid', $nest(OperationAttempts::EVIDENCE_DEPTH - 1))), 'The deepest readable evidence is written');
same($nest(OperationAttempts::EVIDENCE_DEPTH - 1), OperationAttempts::evidence($deepId, null, 'terminal')['evidence'], 'The deepest written evidence reads back');

// A Los Angeles app keeps deadlines across the daylight-saving rollback, when 01:00 to 02:00 happens twice.
$zone = config('app.timezone'); config(['app.timezone' => 'America/Los_Angeles']);
try {
    $clock->instant = new DateTimeImmutable('2026-11-01T01:00:00-07:00');
    $rollbackId = $operation('writer-rollback');
    $rollback = $in(fn () => $writer->claim($rollbackId, $step, $later(60)));
    $in(fn () => $writer->seal($rollback, new SealTerms(7, str_repeat('a', 64), new Money('8.120000', 'USD'), 'ordinary',
        new DateTimeImmutable('2026-11-01T01:59:00-07:00'))));
    $clock->instant = new DateTimeImmutable('2026-11-01T01:01:00-08:00');
    same(Transition::Stale, $in(fn () => $writer->consumeGrant($rollback)), 'A grant that expired at 01:59 PDT is refused at 01:01 PST');
    same(null, OperationAttempts::attempt($rollback)->grantConsumedAt, 'The refused grant writes nothing');
    $heldId = $operation('writer-rollback-claim');
    $in(fn () => $writer->claim($heldId, $step, new DateTimeImmutable('2026-11-01T01:30:00-08:00')));
    same(Transition::Busy, $in(fn () => $writer->claim($heldId, $step, $later(60))), 'A claim until 01:30 PST is still live at 01:01 PST');
    $in(fn () => $writer->recordTerminal($heldId, 'never_sent_unpaid', []));
    same($clock->now()->getTimestamp(), (new DateTimeImmutable(OperationAttempts::evidence($heldId, null, 'terminal')['recorded_at']))->getTimestamp(),
        'Evidence time reads back as the instant it was recorded');
} finally { config(['app.timezone' => $zone]); }

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
    foreach (['register', 'transfer'] as $action) {
        DB::setDefaultConnection('writer_main');
        $acquisitionId = $operation('writer-'.$action, $action);
        $acquisition = new StepKey($action, 1);
        $claim = $in(fn () => $writer->claim($acquisitionId, $acquisition, $later(60)));
        DB::setDefaultConnection('writer_other');
        same(Transition::Busy, $in(fn () => $writer->claim($acquisitionId, $acquisition, $later(60))), 'Other worker cannot claim the acquisition');
        DB::setDefaultConnection('writer_main');
        same(Transition::Applied, $in(fn () => $writer->seal($claim, $terms())), 'Claim owner seals the acquisition');
        same(Transition::Applied, $in(fn () => $writer->consumeGrant($claim)), 'One worker consumes the grant');
        DB::setDefaultConnection('writer_other');
        same(Transition::Stale, $in(fn () => $writer->consumeGrant($claim)), 'Restarted worker cannot spend the grant again');
        $read = $in(fn () => $writer->openRead($claim, $later(60)));
        expect($read instanceof ReadToken, 'Another worker can read the original send');
        DB::setDefaultConnection('writer_main');
        same(Transition::Busy, $in(fn () => $writer->openRead($claim, $later(60))), 'Other worker cannot replace the active read');
        $wrongRead = new ReadToken($read->attempt, bin2hex(random_bytes(16)), $read->until, $read->inheritedUnresolved);
        same(Transition::Stale, $in(fn () => $writer->settle($wrongRead, 'succeeded', ['order' => '42'])), 'A different read token cannot settle the open acquisition');
        same(Transition::Applied, $in(fn () => $writer->settle($read, 'succeeded', ['order' => '41'])), 'Only the read authority can settle');
        DB::setDefaultConnection('writer_other');
        same(Transition::Stale, $in(fn () => $writer->settle($read, 'succeeded', ['order' => '42'])), 'Settlement survives across independent connections');
    }
} finally { DB::setDefaultConnection('sqlite'); @unlink($file); }

$nativeSuccessful = true;
echo "Domains operation writer: claims, seals, grants, replies, reads and evidence passed.\n";
