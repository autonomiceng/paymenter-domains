<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The one status pill a customer domain shows on the list, the detail header and the service card.
 * Labels and reasons come from DomainStatus; Services\DomainStates picks the state, so every page agrees.
 * A domain without a resolved state (a monitored domain with no current registry facts) shows no pill. */
final class StatusPill
{
    /** DomainStatus colours => client tones. The one place the two vocabularies meet. */
    public const TONES = ['success' => 'ok', 'info' => 'info', 'warning' => 'warn', 'danger' => 'bad', 'gray' => 'muted'];
    /** The tone rule goes by reader: amber means you must act. Under review and a stopped registration are ours to clear, so customers read them as blue. Staff keep the colour in DomainStatus. */
    private const CUSTOMER = ['under_review' => 'info', 'registration_held' => 'info', 'registration_unconfirmed' => 'info'];
    /** @return array{label:string,tone:string,reason:?string} */
    public static function of(?string $state, ?string $reason = null): array
    {
        $status = DomainStatus::for($state, $reason);
        if ($state==='registration_held') { $status['label']='Under review'; }
        return [...$status, 'tone' => self::CUSTOMER[(string) $state] ?? self::CUSTOMER[DomainStatus::key($state)] ?? self::TONES[$status['tone']]];
    }
    /** The customer filter groups a staff-held registration under its visible word. */
    public static function key(?string $state): string
    { return $state==='registration_held'?'under_review':DomainStatus::key($state); }
    /** @return array{label:string,tone:string,reason:?string}|null */
    public static function summary(DomainSummary $s): ?array
    { return $s->statusState === null ? null : self::of($s->statusState, $s->statusReason); }
    /** The detail header may pass its summary; the owned status already resolves the same state. */
    public static function owned(OwnedDomain $d, ?DomainSummary $s = null): array
    { return ['label' => $d->status, 'tone' => $d->tone, 'reason' => $d->statusReason]; }
}
