<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Support\Facades\{Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Data\SendState;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\RegistrarCheck;

/** Moves a previously sealed acquisition into read-only attempt authority without changing its evidence identity. */
final readonly class RetainedAcquisition
{
    private function __construct(
        public StepKey $step,
        public string $claimKey,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $sealedAt,
        public string $digest,
        public SendState $sendState,
        public array $envelope,
        public array $funding,
    ) {}

    /** Read-only preflight for offering a check or a registrar answer. */
    public static function adoptable(Operation $op, \DateTimeImmutable $now): bool
    {
        if ($current = $op->acquisitionAttemptFacts()) { return $current->sealed() && !$current->released; }
        return self::legacyRecord($op, $now) !== null;
    }

    private static function legacyRecord(Operation $op, \DateTimeImmutable $now): ?self
    {
        $step = AcquisitionAttempt::key($op->action);
        $a = $op->attempt ?? [];
        if (!$step || ($a['seal'] ?? null) !== 'sealed' || !is_string($a['claim_key'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $a['claim_key']) || !is_string($op->request_fingerprint)
            || !preg_match('/^[a-f0-9]{64}$/D', $op->request_fingerprint) || $op->started_at === null
            || !is_string($a['dispatch_attempted_at'] ?? null) || !is_string($a['send_state'] ?? null)
            || !($state = SendState::tryFrom($a['send_state'])) || isset($a['reconciliation'])) { return null; }
        $sealed = new \DateTimeImmutable($a['dispatch_attempted_at']);
        if ($sealed < $op->started_at || $sealed >= $now) { return null; }
        return new self($step, $a['claim_key'], $op->started_at, $sealed, $op->request_fingerprint, $state, $a,
            array_intersect_key($op->funding ?? [], ['claim' => true, 'seal' => true]));
    }

    /** The caller already verified the original evidence under the operation and funding locks. */
    public static function adopt(Operation $op, OperationWriter $writer, \DateTimeImmutable $now): ?AcquisitionAttempt
    {
        if ($current = AcquisitionAttempt::of((int) $op->id, $op->action, true)) { return $current; }
        if (!($record = self::legacyRecord($op, $now))) { return null; }
        return DB::transaction(function () use ($op, $writer, $record): AcquisitionAttempt {
            $observation = RegistrarCheck::legacyObservation($op);
            $writer->adoptAcquisition((int) $op->id, $op->transition_revision, $record);
            self::clear($op);
            $adopted = AcquisitionAttempt::of((int) $op->id, $op->action, true);
            if ($observation && $writer->recordObservation($adopted->facts->token, $observation['facts'],
                new \DateTimeImmutable('@'.$observation['at'])) !== Transition::Applied) {
                throw new \DomainException('Could not retain the saved registrar observation.');
            }
            return $adopted;
        });
    }

    /** Only called under the operation lock, in the same transaction as the attempt taking ownership. */
    public static function clear(Operation $op): void
    {
        // Encode the independent funding facts before assigning the attempt projection.
        $funding = $op->getRawOriginal('funding');
        if ($funding !== null) {
            $retained = json_decode(Crypt::decryptString($funding), true, 32, JSON_THROW_ON_ERROR);
            unset($retained['claim'], $retained['seal']);
            $funding = $retained ? Crypt::encryptString(json_encode($retained, JSON_THROW_ON_ERROR)) : null;
        }
        DB::table('domains_operations')->where('id', $op->id)->update([
            'attempt' => null, 'started_at' => null, 'request_fingerprint' => null, 'lease_expires_at' => null, 'funding' => $funding,
        ]);
        $op->refresh();
    }
}
