<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

use Illuminate\Support\Facades\{Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Data\{Money, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAdmission, AcquisitionAttempt, AcquisitionResult,
    AcquisitionResultSchema, AcquisitionWindow, AttemptToken, OperationSchema, OperationWriter, ReadToken, SealTerms, Transition, WireOutcome};

/** Corrupt one persisted acquisition evidence row, bypassing the production writer only in the fixture. */
function changeAcquisitionEvidence(Operation $op, string $kind, Closure $change): Closure
{
    $attempt = $op->acquisitionAttemptFacts() ?? throw new LogicException('The fixture requires a purchase attempt.');
    $query = DB::table(OperationSchema::EVIDENCE)->where([
        'operation_id' => $op->id, 'attempt_id' => $attempt->token->attemptId, 'kind' => $kind,
    ]);
    $row = $query->sole();
    $facts = json_decode(Crypt::decryptString($row->evidence), true, 32, JSON_THROW_ON_ERROR);
    $target = DB::table(OperationSchema::EVIDENCE)->where('id', $row->id);
    $target->update(['evidence' => Crypt::encryptString(json_encode($change($facts), JSON_THROW_ON_ERROR))]);
    return static fn () => $target->update(['evidence' => $row->evidence]);
}

/** Detached legacy evidence for advisory checks that alter one immutable field at a time. */
function legacyAcquisitionFixture(Operation $op): Operation
{
    $legacy = clone $op;
    $legacy->setRawAttributes(array_diff_key($op->getAttributes(), ['id' => true]), true);
    $legacy->forceFill(['attempt' => $op->attempt, 'funding' => $op->funding, 'policy' => $op->policy,
        'started_at' => $op->started_at, 'request_fingerprint' => $op->request_fingerprint, 'lease_expires_at' => $op->lease_expires_at]);
    return $legacy;
}

/** Reset the complete claim state of one disposable order between independent fixture cases. */
function restoreAcquisitionFixture(array $raw): void
{
    DB::transaction(static function () use ($raw): void {
        $steps = DB::table(OperationSchema::STEPS)->where('operation_id', $raw['id']);
        DB::table(OperationSchema::EVIDENCE)->where('operation_id', $raw['id'])->delete();
        DB::table(OperationSchema::ATTEMPTS)->whereIn('step_id', (clone $steps)->select('id'))->delete();
        $steps->delete();
        DB::table('domains_operations')->where('id', $raw['id'])->update($raw);
        if (AcquisitionAttempt::key($raw['action'])) {
            DB::table(AcquisitionResultSchema::TABLE)->where('operation_id', $raw['id'])->delete();
            AcquisitionResultSchema::insert((int) $raw['id']);
        }
    });
}


/** Set up a disposable staff fixture with the transfer's sealed clock and optional live read reservation. */
function sealTransferForPolling(Operation $op, int $start, ?int $readingUntil = null): Operation
{
    DB::transaction(static function () use ($op, $start, $readingUntil): void {
        $op->refresh();
        $reply = array_intersect_key($op->attempt ?? [], ['execution' => true, 'corrections' => true]);
        restoreAcquisitionFixture(array_replace($op->getRawOriginal(), [
            'attempt' => null, 'started_at' => null, 'lease_expires_at' => null, 'request_fingerprint' => null,
        ]));
        $at = new DateTimeImmutable('@'.$start);
        $end = $at->modify('+300 seconds');
        $digest = hash('sha256', $op->operation_key);
        $writer = OperationWriter::at($at);
        $token = $writer->claim((int) $op->id, AcquisitionAttempt::key('transfer'), $end, ['digest' => $digest]);
        if (!$token instanceof AttemptToken) { throw new LogicException('Could not claim the fixture transfer.'); }
        $origin = AcquisitionAdmission::FirstClaim;
        $authority = $writer->recordAcquisitionAdmission((int) $op->id, $origin, 1, $token, []);
        $window = new AcquisitionWindow(1, $origin->value, null, $authority, $at, $end, null, 'write_rate_limited');
        $terms = new SealTerms($op->transition_revision, $digest, new Money($op->amount, $op->currency), 'standard', $end);
        if ($writer->seal($token, $terms, ['window' => $window->seal(true)]) !== Transition::Applied
            || $writer->recordReply($token, WireOutcome::reply(SendState::Sent, 'pending', $reply)) !== Transition::Applied) {
            throw new LogicException('Could not seal the fixture transfer.');
        }
        if ($readingUntil !== null) {
            $until = new DateTimeImmutable('@'.$readingUntil);
            $reader = OperationWriter::at($until->modify('-120 seconds'));
            if (!$reader->openRead($token, $until) instanceof ReadToken) {
                throw new LogicException('Could not reserve the fixture transfer read.');
            }
        }
    });
    return $op->refresh();
}
