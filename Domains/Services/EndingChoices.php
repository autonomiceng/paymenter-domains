<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** TLDs we never offer, from config domains.hidden_endings. */
final class EndingChoices
{
    /** @return list<string> */
    public static function hiddenList(): array
    {
        return array_map(static fn($name) => strtolower(ltrim(trim((string)$name), '.')), (array)config('domains.hidden_endings', []));
    }

    /** One already saved stays on the list for renewals only. */
    public static function hidden(string $ending): bool
    {
        return in_array($ending, self::hiddenList(), true);
    }

    /** Hidden endings read like ones staff turned off: no new sales, renewals unchanged. */
    public static function closeHidden(array $endings): array
    {
        foreach ($endings as $ending => $row) {
            if (self::hidden((string) $ending)) { $endings[$ending] = ['register' => [], 'transfer' => [], 'registry' => false] + $row; }
        }
        return $endings;
    }
}
