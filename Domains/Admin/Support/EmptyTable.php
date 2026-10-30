<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Tables\Table;
/** An empty staff table says what fills it. A search or filter that hid the rows says so instead. */
final class EmptyTable
{
    public static function apply(Table $table, string $empty, string $next, string $noMatch): Table
    {
        $hidden=fn($livewire)=>filled($livewire->getTableSearch())||$livewire->getTable()->isFiltered();
        return $table->emptyStateHeading(fn($livewire)=>$hidden($livewire)?$noMatch:$empty)->emptyStateDescription(fn($livewire)=>$hidden($livewire)?null:$next);
    }
}
