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
    /** @return array{label:string,tone:string,reason:?string} */
    public static function of(?string $state, ?string $reason = null): array
    {
        $status = DomainStatus::for($state, $reason);
        return [...$status, 'tone' => self::TONES[$status['tone']]];
    }
    /** @return array{label:string,tone:string,reason:?string}|null */
    public static function summary(DomainSummary $s): ?array
    { return $s->statusState === null ? null : self::of($s->statusState, $s->statusReason); }
    /** The detail header may pass its summary; the owned status already resolves the same state. */
    public static function owned(OwnedDomain $d, ?DomainSummary $s = null): array
    { return ['label' => $d->status, 'tone' => $d->tone, 'reason' => $d->statusReason]; }
}
