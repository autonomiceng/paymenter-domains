<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
/** A staff list note as one short clause (P-TBL-7): the step, not the explanation, which is on the record's page.
 * Staff only; customers never see these notes. */
final class ListNote
{
    /** How staff restart a stopped sync (DomainDecisions::stoppedStep): after "Sync stopped." it is not the row's own step. */
    private const RESTART = '/^(?:Use (?:Sync now|Resume sync)\b|Ask staff who can resume sync\b)/';
    /** Long first sentences and the clause a list says instead. */
    private const SHORT = [
        "/^couldn't confirm the order's contact at .+$/i" => 'Contact not confirmed at registrar',
        '/^registrar balance is too low$/i' => 'Registrar balance too low',
    ];
    /** The first sentence, without its full stop. A leading "Sent.", "Not sent." or "Ready to send." (or "Not sent: ") is dropped, so the
     * clause is the reason, not the bare status the Status column already shows. A leading "Sync stopped." gives way to the step after it. A request it names reads "Request #N needs a check". */
    public static function clause(?string $text, ?int $request = null): ?string
    {
        $text = trim((string) $text);
        if ($text === '') { return null; }
        $text = (string) preg_replace('/^(?:Not sent|Sent|Ready to send)(?::\s*|\.\s+(?=\S))/i', '', $text);
        [$first, $rest] = preg_split('/(?<=[.!?])\s+/', $text, 2) + [1 => ''];
        // "Sync stopped." before the row's own step (an alert, a missing fact) gives way to that step; before only how to
        // restart it ("Use Sync now", "Use Resume sync", "Ask staff who can resume sync"), it is the clause.
        if (preg_match('/^Sync stopped\.$/', $first) === 1 && $rest !== '' && preg_match(self::RESTART, $rest) !== 1) { $first = preg_split('/(?<=[.!?])\s+/', $rest, 2)[0]; }
        $first = rtrim($first, '.');
        $first = (string) preg_replace(array_keys(self::SHORT), array_values(self::SHORT), $first);
        if ($request !== null) { $first = (string) preg_replace('/^(?:Registrar request|Request)\b/', 'Request #'.$request, $first); }
        return ucfirst($first);
    }
}
