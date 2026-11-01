<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Illuminate\Support\HtmlString;
/** The one "?" on staff pages: blue and next to its label. Filament's hintIcon has no focus target; this button shows its tooltip on hover and on keyboard focus. */
final class HelpHint
{
    public static function make(string $text, string $label = 'More help'): Action
    {
        // No click handler: a click must not mount a nested action over an open dialog.
        // Filament pushes hints to the far end of the label row; the auto margin keeps this one beside the label.
        return Action::make('help')->label($label)->icon('heroicon-m-question-mark-circle')->iconButton()->color('primary')->tooltip($text)->livewireClickHandlerEnabled(false)
            ->extraAttributes(['style' => 'margin-inline-end:auto']);
    }
    /** Text with the same "?" beside it, for page HTML outside a field or entry. */
    public static function beside(string $html, string $text, string $label = 'More help'): HtmlString
    { return new HtmlString('<span style="display:inline-flex;align-items:center;gap:.75rem">'.$html.self::make($text, $label)->toHtml().'</span>'); }
}
