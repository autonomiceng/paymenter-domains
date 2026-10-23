<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** Shared read-only calendar for native billing and adoption review. */
final class BillingCalendar
{
    public static function timing(\Illuminate\Support\Collection $settings): array
    {
        $values = $settings->pluck('value', 'key');
        $issue = self::days($values->get('domains_issue_days', 21)); $due = self::days($values->get('domains_due_days', 7));
        self::requireTiming($issue, $due); return compact('issue', 'due');
    }
    public static function dates(\DateTimeImmutable $expiry, int $issue, int $due): array
    {
        self::requireTiming($issue, $due);
        return ['expiry' => $expiry->format('Y-m-d'), 'issue' => $expiry->modify('-'.$issue.' days')->format('Y-m-d'),
            'due' => $expiry->modify('-'.$due.' days')->format('Y-m-d')];
    }
    private static function requireTiming(int $issue, int $due): void
    { if ($issue <= $due || $due < 1 || $issue > 365) { throw new \DomainException('Valid billing calendar required.'); } }
    private static function days(mixed $value): int
    { if (filter_var($value, FILTER_VALIDATE_INT) === false) { throw new \DomainException('Invalid renewal timing.'); } return (int) $value; }
}
