<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Storage guard only; discovery owns transaction lock order and final owner rechecks. */
final class WatchManagedTarget
{
    public const MANAGED = ['active', 'grace', 'expired'];
    public const PROGRESS = ['registration_pending', 'transfer_pending'];
    public static function resolve(int $id, int $userId, string $name): Domain
    {
        $domain = Domain::whereKey($id)->where('user_id', $userId)->where('reservation_name', $name)->first();
        if (!$domain || $domain->adoptionPending() || !in_array($domain->status, [...self::MANAGED, ...self::PROGRESS], true)
            || (int) $domain->service?->user_id !== $userId) { throw new \DomainException('Managed watch target changed.'); }
        return $domain;
    }
}
