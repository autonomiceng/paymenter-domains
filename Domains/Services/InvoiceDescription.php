<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Carbon\CarbonImmutable;

/**
 * Creation-time text only; never rebuild an issued line from current holding facts.
 *
 * Shape: "<What>: <name> (<period>)", for example "Domain registration: example.com (1 year, Oct 19, 2026 to
 * Oct 19, 2027)". It reads as one line where a screen shows the raw text. Invoice views can show the part
 * before the final parentheses as the title and the period under it.
 */
final class InvoiceDescription
{
    // Native invoice_items.description is string(), hence VARCHAR(255). No schema override.
    private const LIMIT = 255;

    public static function acquisition(string $name, string $action, int $years, \DateTimeImmutable $issued): string
    {
        return $action === 'transfer'
            ? self::line('Domain transfer: ', $name, self::term($years).', added to the current expiry date')
            : self::line('Domain registration: ', $name, self::term($years).', '.self::period($issued, $years));
    }

    public static function renewal(string $name, \DateTimeInterface $expiry, int $years): string
    {
        return self::line('Domain renewal: ', $name, self::term($years).', '.self::period($expiry, $years));
    }

    public static function privacy(string $name, ?string $base, \DateTimeImmutable $issued): string
    {
        // Privacy is an independent annual subscription. Renewal starts at its frozen expiry;
        // purchase dates are expected from invoice day, while dispatch establishes actual cover.
        $start = $base === null ? $issued : new \DateTimeImmutable($base);
        return self::line('WHOIS privacy: ', $name, self::term(1).', '.self::period($start, 1));
    }

    private static function term(int $years): string
    {
        return $years === 1 ? '1 year' : $years.' years';
    }

    private static function period(\DateTimeInterface $start, int $years): string
    {
        $date = CarbonImmutable::instance($start);
        return $date->format('M j, Y').' to '.$date->addYearsNoOverflow($years)->format('M j, Y');
    }

    private static function line(string $prefix, string $name, string $period): string
    {
        // Retained IDNs are customer-facing Unicode, without adding an ASCII duplicate.
        $name = idn_to_utf8($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $name;
        $suffix = ' ('.$period.')';
        $room = self::LIMIT - mb_strlen($prefix.$suffix, 'UTF-8');
        if (mb_strlen($name, 'UTF-8') > $room) {
            // Preserve both ends of the name and every date/term qualifier. Never cut UTF-8 bytes.
            $head = intdiv($room - 1, 2); $tail = $room - 1 - $head;
            $name = mb_substr($name, 0, $head, 'UTF-8').'…'.mb_substr($name, -$tail, null, 'UTF-8');
        }
        return $prefix.$name.$suffix;
    }
}
