<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Management\Changes;

use Illuminate\Contracts\Encryption\DecryptException;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AttemptFacts, ManagementEvidenceKind, OperationAttempts};

/** Saved management facts for existing consumers. Send and read authority use the writer's tokens. */
final readonly class ManagementAttempt
{
    private function __construct(public ?AttemptFacts $facts, public array $attempt, public array $funding) {}

    public static function applies(string $action): bool
    {
        return $action === 'auto_renew_off' || in_array($action, ManagementProgress::ACTIONS, true);
    }

    public static function many(array $ids, bool $lock = false): array
    {
        $facts = OperationAttempts::latestManagement($ids, $lock);
        $evidence = OperationAttempts::managementEvidence($ids, $lock);
        $attempts = [];
        foreach ($ids as $id) {
            $live = $facts[$id] ?? null;
            try { $attempts[$id] = ['facts'=>$live, 'projection'=>self::fromRows($live, $evidence[$id] ?? [])]; }
            catch (DecryptException $error) { $attempts[$id] = ['facts'=>$live, 'projection'=>$error]; }
        }
        return $attempts;
    }

    private static function fromRows(?AttemptFacts $facts, array $rows): ?self
    {
        if (!$facts && !$rows) { return null; }
        $attempt = $facts ? ['seal'=>$facts->sealed() ? 'sealed' : 'absent',
            'send_state'=>$facts->sendState->value, 'management_claim'=>$facts->token->claimKey] : [];
        if ($facts?->sealed()) { $attempt['dispatch_attempted_at'] = $facts->sealedAt->format(DATE_ATOM); }
        $funding = [];
        $deferrals = [];
        $retirement = null;
        foreach ($rows as $row) {
            if ($row['unreadable'] ?? false) { throw new DecryptException('Management attempt evidence is unreadable.'); }
            $proof = $row['evidence'];
            $kind = $row['kind'];
            if ($kind === 'terminal' && $row['outcome'] === 'management_retired') {
                $retirement = $proof['approval'];
                $attempt = array_replace($attempt, $proof['retained'] ?? []);
            }
            if ($kind === 'claim') { array_push($deferrals, ...($proof['management_preparation']['management_deferrals'] ?? [])); }
            if ($kind === ManagementEvidenceKind::Deferral->value) { $deferrals[] = $row['recorded_at']; }
            if ($row['attempt_id'] !== $facts?->token->attemptId) { continue; }
            match ($kind) {
                'admission' => $funding = isset($proof['funding']) ? ['seal'=>$proof['funding']] : [],
                'reply' => self::reply($attempt, $row),
                ManagementEvidenceKind::Retained->value => [$attempt, $funding] = [$proof['attempt'], $proof['funding']],
                ManagementEvidenceKind::ReserveRead->value => self::reservation($attempt, $row),
                ManagementEvidenceKind::Readback->value => self::readback($attempt, $row),
                'management_failed' => $attempt['management_staff_outcome'] = $proof,
                default => null,
            };
        }
        if ($deferrals) { $attempt['management_deferrals'] = $deferrals; }
        if ($retirement) {
            $attempt = ['seal'=>'absent', 'send_state'=>'definitely_unsent', ...$attempt, 'management_terminal'=>$retirement];
        }
        return new self($facts, $attempt, $funding);
    }

    private static function reply(array &$attempt, array $row): void
    {
        $proof = $row['evidence'];
        if (isset($proof['management_ack'])) { $attempt['management_ack'] = $proof['management_ack']; }
        if ($row['source'] === 'local_refusal') {
            $flag = ($proof['reason'] ?? null) === 'test_capacity' ? 'management_test_unsent' : 'management_unsent';
            $attempt[$flag] = true;
        }
    }

    private static function reservation(array &$attempt, array $row): void
    {
        $at = $attempt['management_read_at'] = $row['recorded_at'];
        $mode = $row['outcome'];
        if ($mode === 'recovery') {
            $attempt['management_recovery'] = ['number'=>($attempt['management_recovery']['number'] ?? 0) + $row['recovery_count'],
                'at'=>$at, 'reviewed'=>$row['evidence']['reviewed']];
            return;
        }
        $field = $mode === 'legacy' ? 'management_legacy_observation_attempts' : 'management_observation_attempts';
        $attempt[$field] = ($attempt[$field] ?? 0) + 1;
    }

    private static function readback(array &$attempt, array $row): void
    {
        $entry = $row['evidence']['readback'];
        if (isset($entry['recovery_number'])) { $attempt['management_recovery_read'] = $entry; }
        else { $attempt['management_reads'][] = $entry; }
        if ($row['evidence']['refused']) {
            $attempt['management_refusal'] = array_intersect_key($entry, ['operation_key'=>true, 'generation'=>true, 'action'=>true]);
        }
    }
}
