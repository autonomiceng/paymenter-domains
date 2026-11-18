<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Data\TransferStatus;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};

/** The second week observes only a known pending transfer of the original funded claim. */
final class TransferFollowup
{
    public const DAY = 86400;

    public static function observation(Operation $op): ?array
    {
        $r = $op->policy['reconciliation'] ?? [];
        $identity = RegistrarAnswers::identity($op);
        if (isset($r['last_transfer_read'])) {
            $read = $r['last_transfer_read'];
            return ($read['identity'] ?? null) === $identity ? $read : null;
        }

        // Before separate observations were saved, only a matching, finished check dates the status.
        $check = $op->policy['status_check'] ?? [];
        $at = $r['read_at'] ?? null;
        $status = $r['transfer_status'] ?? null;
        if (($check['attempt_identity'] ?? null) !== $identity || ($check['read_attempted'] ?? false) !== true
            || ($check['outcome'] ?? null) !== 'checked' || !is_int($at) || !isset($check['started'], $check['at'])
            || $at < $check['started'] || $at > $check['at'] || !is_string($status) || !TransferStatus::tryFrom($status)) {
            return null;
        }
        return ['at' => $at, 'result' => $status, 'identity' => $identity, 'conflict' => ($check['facts']['owned'] ?? false) === true];
    }

    /** Keep the legacy checked result when a local reservation or staff resume replaces status_check. */
    public static function retain(Operation $op): array
    {
        $policy = $op->policy ?? [];
        if ($op->action === 'transfer' && !isset($policy['reconciliation']['last_transfer_read']) && $read = self::observation($op)) {
            $policy['reconciliation']['last_transfer_read'] = $read;
        }
        return $policy;
    }

    public static function record(Operation $op, array $policy, int $at, string $result, bool $conflict = false): array
    {
        $policy['reconciliation']['last_transfer_read'] = ['at' => $at, 'result' => $result,
            'identity' => RegistrarAnswers::identity($op), 'conflict' => $conflict];
        return $policy;
    }

    public static function stopReason(Operation $op, ?array $read): ?string
    {
        $r = $op->policy['reconciliation'];
        $reason = $r['reason'] ?? null;
        if ($reason !== 'transfer_deadline_passed') {
            return $reason ?? 'transfer_followup_unproven';
        }
        if (($r['read_failed'] ?? false) || in_array($read['result'] ?? null, ['unavailable','unknown'], true)) {
            return 'transfer_status_unavailable';
        }
        if (($r['transfer_status'] ?? null) === 'needs_action') {
            return 'transfer_needs_action';
        }
        // A partial status/history pass had a wire but no usable new conclusion. Its reservation still paces the retry.
        $partial = ($read['result'] ?? null) === 'incomplete' && isset($r['capacity_wait_since']);
        if ((!$partial && ($read['result'] ?? null) !== 'pending') || ($read['conflict'] ?? false)
            || ($r['transfer_status'] ?? null) !== 'pending' || !self::pendingClaim($op)) {
            return 'transfer_followup_unproven';
        }
        if (!RuntimeDispatch::matchesHolding($op, $op->domain, Account::where('server_id', $op->server_id)->first())) {
            return 'transfer_authority_changed';
        }
        return null;
    }

    private static function pendingClaim(Operation $op): bool
    {
        $a = $op->attempt;
        $funding = $op->funding ?? [];
        $reference = $a['evidence']['transferReference'] ?? null;
        // NameSilo keys the status query by the sealed name. Enom needs the original transfer order number.
        $addressable = $op->registrar_key === 'namesilo' || ($op->registrar_key === 'enom' && is_string($reference)
            && preg_match('/^[1-9][0-9]{0,19}$/D', $reference));
        return $addressable && $op->state === 'uncertain'
            && preg_match('/^[0-9a-f]{32}$/D', $a['claim_key'] ?? '') && ($a['send_state'] ?? null) === 'possibly_sent'
            && ($a['execution']['send_state'] ?? null) === 'sent' && ($a['execution']['disposition'] ?? null) === 'pending'
            && ($a['evidence']['transfer'] ?? null) === 'pending'
            && $op->completed_at === null && $op->confirmed_expires_at === null && $op->provider_order_id === null
            && !isset($a['transfer_terminal']) && !isset($a['reconciliation']) && !isset($op->policy['enom_answer'])
            && ($a['charge'] ?? null) === null && ($a['order_candidates'] ?? []) === [] && !array_key_exists('actual', $op->cost ?? [])
            && isset($funding['claim'], $funding['seal']) && $funding['claim'] === $funding['seal']
            && !array_diff(array_keys($funding), ['claim','seal']);
    }

    /** Called under the authority and funding locks on both sides of the provider read. */
    public static function requireClaim(Operation $op, Domain $domain, array $funding): void
    {
        $admission = $op->attempt['transfer_admission'] ?? [];
        if ($domain->adoptionPending() || !isset($admission['cost'], $op->attempt['authorized_cost'])
            || ($admission['name'] ?? null) !== $domain->domain || ($admission['years'] ?? null) !== $op->term
            || ($admission['cost'] ?? null) !== ($op->attempt['authorized_cost'] ?? null)
            || $op->request_fingerprint !== OperationClaims::digest($op, $domain)
            || $op->funding['seal'] !== $funding) {
            throw new \DomainException('Original transfer claim changed.');
        }
    }
}
