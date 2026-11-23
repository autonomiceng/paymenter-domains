<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Data\{Disposition, SendState};

/** Decisions for a worker holding the current operation facts. */
final class RenewalDispatch
{
    public static function canAdmit(string $state, bool $virgin): bool
    {
        return $virgin && in_array($state, ['awaiting_payment', 'queued'], true);
    }

    public static function reply(string $state, ?string $code, ?SendState $send, ?Disposition $disposition,
        bool $inTime): array
    {
        if ($state !== 'running') {
            return ['state' => $state, 'safe_code' => $code];
        }
        if ($send === SendState::DefinitelyUnsent) {
            return ['state' => 'held', 'safe_code' => 'renewal_definitely_unsent'];
        }
        $accepted = $inTime && in_array($disposition, [Disposition::Accepted, Disposition::Pending], true);
        return ['state' => $accepted ? 'pending' : 'uncertain', 'safe_code' => 'renewal_confirmation_required'];
    }

    public static function requireFunding(int $totalMinor, int $netMinor): void
    {
        if ($totalMinor <= 0) {
            throw new \DomainException('Invalid renewal invoice total.');
        }
        if ($netMinor < $totalMinor) {
            throw new RenewalHold('renewal_invoice_unpaid', 'The full invoice must remain paid.');
        }
    }
}
