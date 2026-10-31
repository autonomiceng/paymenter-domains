<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
/** A value the page expects but does not have. One word for customers and staff, never a pill. Every Missing says what
 * to do next: its one fix, or a cause that names the step. Danger text with a warning icon when the reader must act.
 * When nothing is needed, the cause says so and the word goes muted with no icon, so word and tone cannot disagree.
 * Values that do not apply are hidden, not Missing; "Off" is a value. */
final class Missing
{
    /** Cause for a price or offer a customer needs before buying. */
    public const ASK_FIRST = 'Contact support before you buy';
    public const LABEL = 'Missing';
    public const ICON = 'heroicon-m-exclamation-triangle';
    public const COLOR = 'danger';
    public const FILLS = 'Fills in by itself';
    public const NO_ACTION = 'No action needed.';
    public static function is(mixed $value): bool { return $value === null || $value === '' || $value === []; }
    /** A cause for a value nobody has to act on: "Fills in by itself" and when. */
    public static function fills(string $when): string { return self::FILLS.' '.$when; }
    public static function needsNothing(?string $cause): bool
    { return $cause !== null && (str_starts_with($cause, self::FILLS) || str_ends_with($cause, self::NO_ACTION)); }
    public static function color(?string $cause = null): string { return self::needsNothing($cause) ? 'gray' : self::COLOR; }
    public static function icon(?string $cause = null): ?string { return self::needsNothing($cause) ? null : self::ICON; }
    /** Where "Contact support" goes: the ticket form, else config `domains.support_url` (an http:, https:, mailto: or tel: link with a real destination), else nowhere, and System checks tells staff. */
    public static function supportUrl(): ?string
    {
        if (!config('settings.tickets_disabled', false) && Route::has('tickets.create')) { return route('tickets.create'); }
        $url = trim((string) config('domains.support_url'));
        $web = in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
        $usable = $web ? (string) parse_url($url, PHP_URL_HOST) !== '' : preg_match('#^(mailto:[^@\s]+@[^@\s]|tel:\+?[0-9])#i', $url) === 1;
        return $usable ? $url : null;
    }
    /** The fix when only people can help. With no way to reach us set up, the step still shows as plain words. */
    public static function support(?string $url): array { return ['label' => 'Contact support', 'url' => $url]; }
    /** Client markup. $fix is ['label' => ..., 'url' => ...] or markup such as a button form. */
    public static function html(?string $cause = null, array|Htmlable|null $fix = null): HtmlString
    {
        return new HtmlString(view('domains::partials.missing', ['cause' => $cause, 'fix' => $fix])->render());
    }
}
