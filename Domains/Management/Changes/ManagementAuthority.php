<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Management\Changes;

use Illuminate\Support\Facades\{Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Money, RequestContext, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AttemptToken, OperationAttempts, OperationWriter, ReadToken, SealTerms, StepKey, Transition, WireOutcome};
use Paymenter\Extensions\Servers\Domains\Presentation\StaffTime;
use Paymenter\Extensions\Servers\Domains\Services\{ManagementReadRecovery, StaffReviewRefused};

/** Called after the holding and operation locks, with the feature's binding, owner and native funding checked. */
final readonly class ManagementAuthority
{
    private OperationWriter $writer;

    public function __construct(private Clock $clock) { $this->writer = new OperationWriter($clock); }

    public function claim(Operation $op, \DateTimeImmutable $until): AttemptToken
    {
        $history = $op->managementAttempt() === null ? $op->attempt['management_deferrals'] ?? [] : [];
        $approved = $op->policy['management'];
        $token = $this->writer->claim((int) $op->id, StepKey::management(), $until, [
            'management_approval'=>['actor_id'=>(int) $op->authorized_by, 'generation'=>$op->authority_generation,
                'input_digest'=>hash('sha256', json_encode($approved['input'], JSON_THROW_ON_ERROR))],
            ...($history ? ['management_preparation'=>['management_deferrals'=>$history]] : []),
        ]);
        if (!$token instanceof AttemptToken) { throw new \DomainException('Management attempt already exists.'); }
        self::clearLegacy($op);
        return $token;
    }

    public function seal(Operation $op, AttemptToken $token, RequestContext $context, array $funding): ReadToken
    {
        $approval = OperationAttempts::evidence($token->operationId, $token->attemptId, 'claim')['evidence']['management_approval'] ?? null;
        if ($token->operationId !== (int) $op->id || $approval !== [
            'actor_id'=>(int) $op->authorized_by, 'generation'=>$op->authority_generation,
            'input_digest'=>hash('sha256', json_encode($op->policy['management']['input'], JSON_THROW_ON_ERROR)),
        ]) {
            throw new \DomainException('Management approval changed.');
        }
        $digest = hash('sha256', json_encode([$op->operation_key, $op->authorized_by, $op->authority_generation,
            $op->account_fingerprint, $op->payload], JSON_THROW_ON_ERROR));
        $cost = $funding ? $op->payload['privacy_terms']['cost'] ?? null : '0.00';
        if (!is_string($cost)) { throw new \DomainException('Management spending terms unavailable.'); }
        $terms = new SealTerms($op->transition_revision, $digest, new Money($cost, $op->currency), 'management', $context->deadline);
        if ($this->writer->seal($token, $terms, $funding ? ['funding'=>$funding] : null) !== Transition::Applied
            || $this->writer->consumeGrant($token) !== Transition::Applied) {
            throw new \DomainException('Management claim changed.');
        }
        $read = $this->writer->openManagementRead($token, $context->deadline, ['mode'=>'ordinary']);
        if (!$read instanceof ReadToken) { throw new \DomainException('Management readback unavailable.'); }
        return $read;
    }

    public function acknowledge(Operation $op, ReadToken $read, array $facts): void
    {
        if ((int) $op->id !== $read->attempt->operationId
            || $this->writer->recordReply($read->attempt, WireOutcome::reply(SendState::Sent, 'accepted', ['management_ack'=>$facts])) !== Transition::Applied) {
            throw new \DomainException('Acknowledgement unavailable.');
        }
    }

    public function stop(Operation $op, AttemptToken $token, bool $defer = false, ?string $refusal = null): void
    {
        $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->current || (int) $op->id !== $token->operationId) { throw new \DomainException('Management claim changed.'); }
        if (!$facts->sealed()) {
            $result = $defer ? $this->writer->deferManagement($token) : $this->writer->releasePreSeal($token);
            if ($result !== Transition::Applied) { throw new \DomainException('Management claim changed.'); }
        } elseif ($refusal !== null
            && $this->writer->recordReply($token, WireOutcome::localRefusal($refusal)) !== Transition::Applied) {
            throw new \DomainException('Management send already has a reply.');
        }
        $op->reloadManagementAttempt();
    }

    public function restoreDeferred(Operation $op): void
    {
        $facts = $op->managementAttempt()?->facts;
        if (!$facts) {
            $token = $this->claim($op, $this->clock->now()->modify('+1 second'));
            $result = $this->writer->deferManagement($token);
        } else {
            $result = $this->writer->restoreManagementPreparation($facts->token);
        }
        if ($result !== Transition::Applied) { throw new \DomainException('Management claim changed.'); }
        $op->reloadManagementAttempt();
    }

    public function reserve(Operation $op, \DateTimeImmutable $until, bool $reviewed): ?ReadToken
    {
        $attempt = $op->attempt;
        $mode = $this->readMode($attempt, $reviewed);
        if ($mode === null) { return null; }
        if ($reviewed) { self::requireReview($op); }
        $token = $this->retainedToken($op);
        if (!$token) { return null; }
        $read = $this->writer->openManagementRead($token, $until, ['mode'=>$mode, 'reviewed'=>$reviewed]);
        return $read instanceof ReadToken ? $read : null;
    }

    private function readMode(array $a, bool $reviewed): ?string
    {
        if (ManagementReadRecovery::exhausted($a)) {
            $next = ManagementReadRecovery::last($a)?->modify($reviewed ? '+15 minutes' : '+6 hours');
            if (!$next || $next <= $this->clock->now()) { return 'recovery'; }
            if ($reviewed) { throw new StaffReviewRefused('A status check was requested recently. Try again at '.StaffTime::at($next->getTimestamp()).'. Automatic checks continue.'); }
            return null;
        }
        if (array_key_exists('management_observation_attempts', $a)) {
            $count = $a['management_observation_attempts'];
            return is_int($count) && $count >= 1 && $count < 20 ? 'ordinary' : null;
        }
        $count = $a['management_legacy_observation_attempts'] ?? 0;
        return $reviewed && is_int($count) && $count >= 0 && $count < 20 ? 'legacy' : null;
    }

    private static function requireReview(Operation $op): void
    {
        $actions = $op->policy['staff_actions'] ?? [];
        $review = end($actions);
        if (($review['action'] ?? null) !== 'recheck' || ($review['phase'] ?? null) !== 'read_requested'
            || ($review['operation_key'] ?? null) !== $op->operation_key || ($review['generation'] ?? null) !== $op->authority_generation) {
            throw new \DomainException('Reviewed legacy management recovery required.');
        }
    }

    private function retainedToken(Operation $op): ?AttemptToken
    {
        if ($op->managementAttempt()?->facts) { return $op->managementAttempt()->facts->token; }
        $attempt = $op->attempt;
        if (($attempt['seal'] ?? null) !== 'sealed' || !is_string($attempt['dispatch_attempted_at'] ?? null)) { return null; }
        $at = new \DateTimeImmutable($attempt['dispatch_attempted_at']);
        if ($at > $this->clock->now()) { return null; }
        $token = $this->writer->adoptManagement((int) $op->id, $at, $op->started_at, $attempt,
            array_intersect_key($op->funding ?? [], ['seal'=>true]));
        self::clearLegacy($op);
        return $token;
    }

    public function finish(Operation $op, ReadToken $read, array $entry, bool $refused, ?string $settlement): void
    {
        if ((int) $op->id !== $read->attempt->operationId
            || $this->writer->finishManagementRead($read, ['readback'=>$entry, 'refused'=>$refused], $settlement) !== Transition::Applied) {
            throw new \DomainException('Management readback no longer applicable.');
        }
        $this->writer->closeRead($read);
        $op->reloadManagementAttempt();
    }

    public function close(ReadToken $read): void { $this->writer->closeRead($read); }

    public function fail(Operation $op, array $approval): void
    {
        if (!($token = $this->retainedToken($op)) || $this->writer->failManagement($token, $approval) !== Transition::Applied) {
            throw new \DomainException('Management readback no longer applicable.');
        }
        $op->reloadManagementAttempt();
    }

    public static function retire(Operation $op, array $approval, \DateTimeImmutable $at): void
    {
        $previous = $op->managementAttempt() === null ? $op->attempt : null;
        if (OperationWriter::at($at)->recordTerminal((int) $op->id, 'management_retired',
            ['approval'=>$approval, ...($previous ? ['retained'=>$previous] : [])]) !== Transition::Applied) {
            throw new \DomainException('Management retirement already recorded.');
        }
        self::clearLegacy($op, keepDates: true);
        $op->update(['state'=>'rejected', 'safe_code'=>'management_retired', 'completed_at'=>$at]);
    }

    private static function clearLegacy(Operation $op, bool $keepDates = false): void
    {
        $stored = $op->receiptFunding();
        $dates = $keepDates ? [] : ['started_at'=>null, 'lease_expires_at'=>null];
        if ($op->getRawOriginal('attempt') !== null || array_key_exists('seal', $stored)
            || array_filter(array_intersect_key($op->getRawOriginal(), $dates))) {
            unset($stored['seal']);
            DB::table('domains_operations')->where('id', $op->id)->update(['attempt'=>null,
                'funding'=>$stored ? Crypt::encryptString(json_encode($stored, JSON_THROW_ON_ERROR)) : null, ...$dates]);
            $op->refresh();
        }
        $op->reloadManagementAttempt();
    }
}
