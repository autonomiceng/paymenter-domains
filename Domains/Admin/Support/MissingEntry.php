<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
/** Staff fact styling for a value that applies but has none: "Missing" in danger with the red hexagon (muted when
 * the cause says nothing is needed), the fix as a hint action and the cause below. Present values keep $color and show no fix or cause.
 * A helper, not a TextEntry macro: macros are global changes to Filament classes. Call it after ->state(). */
final class MissingEntry
{
    /** $calm: a page notice already gives the cause and next step, so Missing reads muted with no mark. */
    public static function apply(TextEntry $entry, ?Action $fix = null, ?string $cause = null, ?string $color = null, bool $causeShown = false, bool $calm = false): TextEntry
    {
        $missing = static fn (mixed $state): bool => $state === Missing::LABEL;
        $entry->default(Missing::LABEL)
            ->color(static fn (mixed $state): ?string => $missing($state) ? ($calm ? 'gray' : Missing::color($cause)) : $color)
            ->icon(static fn (mixed $state): ?HtmlString => $missing($state) && !$calm ? Missing::icon($cause) : null);
        if ($fix) { $entry->hintAction(static fn (TextEntry $component): ?Action => $missing($component->getState()) ? $fix : null); }
        // A shared callout can already give the cause and next step for several missing values.
        if ($cause !== null && !$causeShown) { $entry->belowContent(static fn (TextEntry $component): ?string => $missing($component->getState()) ? $cause : null); }
        return $entry;
    }
}
