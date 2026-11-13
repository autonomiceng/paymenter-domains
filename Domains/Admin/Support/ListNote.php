<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
/** A staff list note as one short clause (P-TBL-7): the step, not the explanation, which is on the record's page.
 * Staff only; customers never see these notes. */
final class ListNote
{
    /** Long first sentences and the clause a list says instead. */
    private const SHORT = [
        "/^couldn't confirm the order's contact at .+$/i" => 'Contact not confirmed at registrar',
        '/^registrar balance is too low$/i' => 'Registrar balance too low',
    ];
    /** The first sentence, without "Not sent: " or its full stop. A request it names reads "Request #N needs a check". */
    public static function clause(?string $text, ?int $request = null): ?string
    {
        $text = trim((string) $text);
        if ($text === '') { return null; }
        $first = preg_split('/(?<=[.!?])\s+/', $text, 2)[0];
        $first = rtrim((string) preg_replace('/^Not sent:\s*/i', '', $first), '.');
        $first = (string) preg_replace(array_keys(self::SHORT), array_values(self::SHORT), $first);
        if ($request !== null) { $first = (string) preg_replace('/^(?:Registrar request|Request)\b/', 'Request #'.$request, $first); }
        return ucfirst($first);
    }
}
