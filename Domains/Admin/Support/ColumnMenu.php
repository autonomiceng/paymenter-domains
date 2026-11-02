<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Table;
use Paymenter\Extensions\Servers\Domains\Services\HookRegistration;
/** Apply columns closes the column menu on every table. Filament leaves the menu open over the table it just changed. */
final class ColumnMenu
{
    public static function boot(): void
    {
        if(!HookRegistration::claim(FilamentView::getFacadeRoot(),self::class)){return;}
        Table::configureUsing(static fn(Table $table)=>$table->columnManagerApplyAction(static fn(Action $action)=>$action->alpineClickHandler('applyTableColumnManager(); close()')));
    }
}
