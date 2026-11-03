<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
/** One order for every dialog: Cancel first, the action that saves last. Stock admin dialogs follow it too. */
final class DialogFooter
{
    public static function boot(): void
    { Action::configureUsing(fn(Action $action)=>$action->modalFooterActions(self::cancelFirst(...))); }
    /** @return list<Action> */
    public static function cancelFirst(Action $action): array
    { return array_values(array_filter([$action->getModalCancelAction(),...$action->getExtraModalFooterActions(),$action->getModalSubmitAction()])); }
}
