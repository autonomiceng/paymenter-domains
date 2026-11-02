<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Paymenter\Extensions\Servers\Domains\Services\HookRegistration;
/** Apply columns and Apply filters close their menu on every table. Filament leaves it open over the table it just changed. */
final class TableMenus
{
    public static function boot(): void
    {
        if(!HookRegistration::claim(FilamentView::getFacadeRoot(),self::class)){return;}
        Table::configureUsing(static fn(Table $table)=>$table->columnManagerApplyAction(static fn(Action $action)=>$action->alpineClickHandler('applyTableColumnManager(); close()'))
            // Only the dropdown has a menu to close. Without one, close() would reach the browser window.
            ->filtersApplyAction(static fn(Action $action)=>$table->getFiltersLayout()===FiltersLayout::Dropdown?$action->alpineClickHandler('$wire.applyTableFilters(); close()'):$action));
    }
}
