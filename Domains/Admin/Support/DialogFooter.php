<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
/** Global admin policy, including other extensions: Cancel first, saving action last; staff CSS keeps that order visible. */
final class DialogFooter
{
    public static function boot(): void
    { Action::configureUsing(fn(Action $action)=>$action->modalFooterActions(self::cancelFirst(...))->extraModalWindowAttributes(['class'=>'domains-dialog'],merge:true)); }
    /** @return list<Action> */
    public static function cancelFirst(Action $action): array
    { return array_values(array_filter([$action->getModalCancelAction(),...$action->getExtraModalFooterActions(),$action->getModalSubmitAction()])); }
}
