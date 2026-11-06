<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Pure readers for optional reviewed facts. Parsing never grants provider authority. */
final class ReviewedFacts
{
    public static function entries(mixed $value): array
    {
        return is_array($value) && ($value['format'] ?? null) === 1
            && !array_diff(array_keys($value), ['format','entries']) && is_array($value['entries'] ?? null)
            ? $value['entries'] : [];
    }
    public static function text(mixed $v): bool
    { return is_string($v) && $v !== '' && trim($v) === $v && strlen($v) <= 255 && !preg_match('/[\x00-\x1f\x7f]/', $v); }
    public static function current(array $v, \DateTimeImmutable $now): bool
    {
        if (!self::text($v['source'] ?? null) || !self::text($v['version'] ?? null)) { return false; }
        $reviewed = self::instant($v['reviewed_at'] ?? null); $until = self::instant($v['valid_until'] ?? null);
        return $reviewed !== null && $until !== null && $reviewed <= $now && $now < $until;
    }
    private static function instant(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v)) { return null; }
        try { $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $v); }
        catch (\ValueError) { return null; }
        return $date && $date->format(DATE_ATOM) === $v ? $date : null;
    }
    public static function integers(mixed $v, int $min, int $max): bool
    {
        if (!is_array($v) || !array_is_list($v) || !$v || count($v) > $max - $min + 1
            || count(array_unique($v, SORT_REGULAR)) !== count($v)) { return false; }
        foreach ($v as $n) { if (!is_int($n) || $n < $min || $n > $max) { return false; } }
        return true;
    }
}
