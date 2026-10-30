<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
/** A "?" for a field or entry. Filament's hintIcon has no focus target; this button shows its tooltip on hover and on keyboard focus. */
final class HelpHint
{
    public static function make(string $text): Action
    {
        // No click handler: a click must not mount a nested action over an open dialog.
        return Action::make('help')->label('More help')->icon('heroicon-m-question-mark-circle')->iconButton()->color('gray')->tooltip($text)->livewireClickHandlerEnabled(false);
    }
}
