<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\SendState;

/** The management step uses the same claim, dispatch, read ownership and settlement keys. */
trait WritesManagementEvidence
{
    /** Preserve a legacy seal as read-only. Its dispatch window has already closed. */
    public function adoptManagement(int $id, \DateTimeImmutable $sealed, ?\DateTimeImmutable $started, array $proof, array $funding): AttemptToken
    {
        self::requireTransaction();
        $key = StepKey::management();
        if (OperationAttempts::latest($id, $key, true)) { throw new \DomainException('Management attempt already exists.'); }
        $stepId = $this->stepId($id, $key);
        $state = SendState::tryFrom($proof['send_state'] ?? '') ?? SendState::PossiblySent;
        $claim = bin2hex(random_bytes(16));
        $closed = OperationAttempts::at($sealed);
        $attemptId = (int) DB::table(OperationSchema::ATTEMPTS)->insertGetId([
            'step_id' => $stepId, 'ordinal' => 1, 'claim_key' => $claim,
            'claimed_at' => OperationAttempts::at($started ?? $sealed), 'claim_until' => $closed,
            'sealed_at' => $closed, 'dispatch_until' => $closed, 'send_state' => $state->value,
            'grant_consumed_at' => $closed, 'mode' => 'management',
        ]);
        $this->append($id, $attemptId, ManagementEvidenceKind::Retained->value, 'retained', 'sealed',
            ['attempt' => $proof, 'funding' => $funding], 'management_retained');
        $this->point($stepId, $attemptId);
        return new AttemptToken($id, $stepId, $attemptId, 1, $claim);
    }

    public function deferManagement(AttemptToken $token): Transition
    {
        self::requireTransaction();
        $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->current || $facts->sealed()) { return Transition::Stale; }
        if (!$this->append($token->operationId, $token->attemptId, ManagementEvidenceKind::Deferral->value, 'local', 'deferred', [], 'management_deferral')) {
            return Transition::Stale;
        }
        $this->archive($facts, $this->clock->now());
        return Transition::Applied;
    }

    public function restoreManagementPreparation(AttemptToken $token): Transition
    {
        self::requireTransaction();
        $facts = OperationAttempts::latest($token->operationId, StepKey::management(), true);
        if (!$facts || $facts->token != $token || !$facts->released || $facts->sealed()) { return Transition::Stale; }
        return $this->append($token->operationId, $token->attemptId, ManagementEvidenceKind::Deferral->value,
            'local', 'restored', [], 'management_restore') ? Transition::Applied : Transition::Stale;
    }

    /** A reservation counts before the provider call, including when the read fails or the worker stops. */
    public function openManagementRead(AttemptToken $token, \DateTimeImmutable $until, array $reservation): ReadToken|Transition
    {
        $read = $this->openRead($token, $until);
        if ($read instanceof ReadToken && !$this->append($token->operationId, $token->attemptId,
            ManagementEvidenceKind::ReserveRead->value, 'local', $reservation['mode'], $reservation, 'read:'.$read->token)) {
            throw new \DomainException('Management read already reserved.');
        }
        return $read;
    }

    public function finishManagementRead(ReadToken $read, array $proof, ?string $settlement): Transition
    {
        self::requireTransaction();
        if (!$this->ownsRead($read)) { return Transition::Stale; }
        $a = $read->attempt;
        if ($settlement !== null) {
            // This same row owns the readback and its one final decision.
            return $this->append($a->operationId, $a->attemptId, ManagementEvidenceKind::Readback->value,
                'registrar_read', $settlement, $proof, 'settlement') ? Transition::Applied : Transition::Stale;
        }
        return $this->append($a->operationId, $a->attemptId, ManagementEvidenceKind::Readback->value,
            'registrar_read', 'unknown', $proof, 'readback:'.$read->token) ? Transition::Applied : Transition::Stale;
    }

    /** A local review consumes the same settlement identity as provider completion. */
    public function failManagement(AttemptToken $token, array $approval): Transition
    {
        self::requireTransaction();
        $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->current || !$facts->sealed() || $facts->reading($this->clock->now())) { return Transition::Stale; }
        return $this->append($token->operationId, $token->attemptId, 'management_failed', 'staff_review',
            'failed', $approval, 'settlement') ? Transition::Applied : Transition::Stale;
    }
}
