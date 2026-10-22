<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Carbon\CarbonImmutable;

/** Creation-time text only; never rebuild an issued line from current holding facts. */
final class InvoiceDescription
{
    // Native invoice_items.description is string(), hence VARCHAR(255). No schema override.
    private const LIMIT = 255;

    public static function acquisition(string $name, string $action, int $years, \DateTimeImmutable $issued): string
    {
        $term = $years.' '.($years === 1 ? 'year' : 'years');
        return $action === 'transfer'
            ? self::line('Transfer ', $name, ': adds '.$term.' to the current expiry')
            : self::line('Register ', $name, ': '.$term.' ('.self::period($issued, $years).', from registration)');
    }

    public static function renewal(string $name, \DateTimeInterface $expiry, int $years): string
    {
        return self::line('Renew ', $name, ': '.self::period($expiry, $years));
    }

    public static function privacy(string $name, ?string $base, \DateTimeImmutable $issued): string
    {
        // Privacy is an independent annual subscription. Renewal starts at its frozen expiry;
        // purchase dates are expected from invoice day, while dispatch establishes actual cover.
        $start = $base === null ? $issued : new \DateTimeImmutable($base);
        return self::line('Domain privacy ', $name, ': '.self::period($start, 1).($base === null ? ' (from purchase)' : ''));
    }

    private static function period(\DateTimeInterface $start, int $years): string
    {
        $date = CarbonImmutable::instance($start);
        // Same padding as native Service::description, so domain and hosting lines on one invoice read alike.
        return $date->format('M d, Y').' - '.$date->addYearsNoOverflow($years)->format('M d, Y');
    }

    private static function line(string $prefix, string $name, string $suffix): string
    {
        // Retained IDNs are customer-facing Unicode, without adding an ASCII duplicate.
        $name = idn_to_utf8($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $name;
        $room = self::LIMIT - mb_strlen($prefix.$suffix, 'UTF-8');
        if (mb_strlen($name, 'UTF-8') > $room) {
            // Preserve both ends of the name and every date/term qualifier. Never cut UTF-8 bytes.
            $head = intdiv($room - 1, 2); $tail = $room - 1 - $head;
            $name = mb_substr($name, 0, $head, 'UTF-8').'…'.mb_substr($name, -$tail, null, 'UTF-8');
        }
        return $prefix.$name.$suffix;
    }
}
