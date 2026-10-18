<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Explicit nonfinancial correction authority; never acquisition completion, refund or replay. */
final class ManagementCorrections
{
    public static function resolvedFor(Operation $peer, Operation $acquisition, Domain $d): bool
    {
        return ($peer->policy['management']['correction'] ?? null) === $acquisition->operation_key
            && CorrectionProgress::matches($peer, $acquisition, $d) && ManagementEvidence::resolved($peer, $d) && ($peer->state === 'succeeded' || ManagementEvidence::idle($peer));
    }
    public static function pending(array $l, string $action, ?string $key): bool
    {
        $op = $key === null ? null : $l['operations']->firstWhere('operation_key', $key); $d = $l['domain'];
        if (!$op || !in_array($action, ['nameservers','contacts','contact_profile','privacy_enable','privacy_disable','auto_renew_off'], true)
            || !in_array($d->status, ['registration_pending','transfer_pending','active'], true) || !in_array($l['service']->status, ['pending','active'], true)
            || !in_array($op->action, ['register','transfer'], true) || !in_array($op->state, ['pending','uncertain','succeeded'], true)
            || $op->account_fingerprint !== $d->account_fingerprint || $op->authority_generation !== $d->authority_generation
            || ($op->attempt['seal'] ?? null) !== 'sealed' || isset($op->funding['refund']) || isset($op->attempt['transfer_terminal'])) { return false; }
        try { RefundPayment::requireCurrent($op, $l); return true; } catch (\Throwable) { return false; }
    }
    public static function require(array $l, string $action, #[\SensitiveParameter] array $input, string $key): void
    {
        if (!self::pending($l, $action, $key)) { throw new \DomainException('Acquisition correction authority unavailable.'); }
        $d = $l['domain']; $op = $l['operations']->firstWhere('operation_key', $key); $desired = $op->payload;
        if ($action === 'nameservers') { $same = ($input['nameservers'] ?? null) === ManagementPayload::nameservers($desired['nameservers'] ?? []); }
        elseif (in_array($action, ['contacts','contact_profile'], true)) {
            $contacts = ContactDetails::validate($d->registrar_key, $desired['contacts'] ?? []); $same = true;
            foreach (($input['role'] ?? null) === 'all' ? ContactDetails::ROLES : [$input['role'] ?? ''] as $role) {
                if (($contacts[$role] ?? null) !== ($input['contact'] ?? null)) { $same = false; }
            }
        } elseif (in_array($action, ['privacy_enable','privacy_disable'], true)) {
            $privacy = $op->action === 'transfer' ? ($op->attempt['transfer_admission']['privacy'] ?? null) : ($desired['privacy'] ?? false);
            $same = is_bool($privacy) && $privacy === ($action === 'privacy_enable');
        } else { $same = $action === 'auto_renew_off'; }
        if (!$same) { throw new \DomainException('Correction differs from frozen acquisition input.'); }
    }
}
