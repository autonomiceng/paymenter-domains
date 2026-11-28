<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{Crypt, DB};

trait WritesOperationEvidence
{
    public function recordAcquisitionCharges(ReadToken $read, AcquisitionChargeMatch $match, array $orders, \DateTimeImmutable $observedAt): Transition
    {
        self::requireTransaction();
        if ($observedAt >= $read->until || !$this->ownsRead($read, allowExpired: true)
            || OperationAttempts::purchaseClosed($read->attempt)) { return Transition::Stale; }
        $a = $read->attempt;
        return $this->append($a->operationId, $a->attemptId, 'acquisition_charge', 'registrar_read',
            $match->value, ['orders'=>$orders], 'charge:'.$match->value, $observedAt) ? Transition::Applied : Transition::Stale;
    }

    /**
     * One row per authority identity; null when that identity already has evidence. The plain read can miss a row
     * committed after the caller's snapshot, so the unique authority key decides.
     */
    private function append(int $operationId, ?int $attemptId, string $kind, string $source, string $outcome, array $evidence,
        string $identity, ?\DateTimeImmutable $observedAt = null): ?int
    {
        $key = hash('sha256', implode('|', [$operationId, $attemptId ?? '-', $identity]));
        if (DB::table(OperationSchema::EVIDENCE)->where('authority_key', $key)->exists()) { return null; }
        // json_decode counts one level more than json_encode, so this is the deepest evidence the reader accepts.
        $encoded = json_encode($evidence, JSON_THROW_ON_ERROR, OperationAttempts::EVIDENCE_DEPTH - 1);
        $now = $this->clock->now();
        try {
            return (int) DB::table(OperationSchema::EVIDENCE)->insertGetId(['operation_id' => $operationId, 'attempt_id' => $attemptId, 'source' => $source,
                'kind' => $kind, 'outcome' => $outcome, 'observed_at' => $observedAt ? OperationAttempts::at($observedAt) : null,
                'recorded_at' => OperationAttempts::at($now), 'authority_key' => $key,
                'evidence' => Crypt::encryptString($encoded)]);
        } catch (UniqueConstraintViolationException $e) {
            // The driver message names the violated key; the statement text always names the column.
            if (!str_contains((string) ($e->errorInfo[2] ?? ''), 'authority_key')) { throw $e; }
            return null;
        }
    }

    private static function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) { throw new \LogicException('Operation writes require the caller\'s locked transaction.'); }
    }
}
