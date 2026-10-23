<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
/** A value the page expects but does not have. One word everywhere, danger text with a warning icon, never a pill.
 * The cause and the one fix sit next to it. When several values share a cause, the page notice holds the fix and
 * the rows only say Missing. Values that do not apply are hidden, not Missing; "Off" is a value. */
final class Missing
{
    public const LABEL = 'Missing';
    public const ICON = 'heroicon-m-exclamation-triangle';
    public const COLOR = 'danger';
    public static function is(mixed $value): bool { return $value === null || $value === '' || $value === []; }
    /** Client markup. $fix is ['label' => ..., 'url' => ...] or markup such as a button form. */
    public static function html(?string $cause = null, array|Htmlable|null $fix = null): HtmlString
    {
        return new HtmlString(view('domains::partials.missing', ['cause' => $cause, 'fix' => $fix])->render());
    }
}
