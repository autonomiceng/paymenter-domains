<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Tables\Table;
/** An empty staff table says what fills it. A search or filter that hid the rows says so, and how to get them back. */
final class EmptyTable
{
    public const CLEAR='Clear the search or filters to see all rows.';
    /** @param ?\Closure():?string $blocked a heading that takes over with no step, such as rows that can't be read */
    public static function apply(Table $table, string $empty, string $next, string $noMatch, ?\Closure $blocked=null): Table
    {
        $hidden=fn($livewire)=>filled($livewire->getTableSearch())||$livewire->getTable()->isFiltered();
        $blocked??=fn()=>null;
        return $table->emptyStateHeading(fn($livewire)=>$blocked()??($hidden($livewire)?$noMatch:$empty))
            ->emptyStateDescription(fn($livewire)=>$blocked()!==null?null:($hidden($livewire)?self::CLEAR:$next));
    }
}
