<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
/**
 * An owned table with more than 4 columns, or with an optional column, gets a Columns button. The first column names the row, so it always stays.
 * Defaults must be right on their own: the choice lasts one session.
 */
final class TableColumns
{
    /** @param list<Column> $columns @param list<string> $hidden columns that start hidden */
    public static function choose(Table $table, array $columns, array $hidden=[]): Table
    {
        if (count($columns)>4 || $hidden!==[]) {
            foreach (array_values($columns) as $i=>$column) {
                $i===0?$column->toggleable(false):$column->toggleable(isToggledHiddenByDefault:in_array($column->getName(),$hidden,true));
            }
            $table->columnManagerTriggerAction(fn(Action $action)=>$action->label('Columns')->button());
        }
        return $table->columns($columns);
    }
}
