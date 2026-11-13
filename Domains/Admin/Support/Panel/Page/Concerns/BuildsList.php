<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Concerns;
use Filament\Actions\{BulkAction, BulkActionGroup};
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{EmptyTable, TableColumns};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Column, Filter};
/** What both list types share: the head, then the standard table. The page supplies columns, filters, the empty line
 * and its bulk actions as data; search, sort, paging, the empty state, the column menu and the row link belong to
 * the type. */
trait BuildsList
{
    use RendersType;
    use InteractsWithTable;
    /** @return list<Column> */
    abstract protected function columns(): array;
    /** @return list<Filter> */
    protected function filters(): array { return []; }
    /** What fills the list when it is empty and the next step: ['No monitored domains yet.', 'Add a domain to start checking it.']
     * @return array{0:string,1:string} */
    abstract protected function emptyLine(): array;
    /** Native bulk actions on the rows staff tick. Each checks its own permission when it runs. @return list<BulkAction> */
    protected function bulkActions(): array { return []; }
    /** The search box's words, when the list is searchable. */
    protected function searchLabel(): ?string { return null; }
    final public function content(Schema $schema): Schema { return $schema->components([EmbeddedTable::make()]); }
    /** @return array{0:list<Column>,1:list<Filter>} */
    private function listParts(): array
    {
        $columns = array_values($this->columns());$filters = array_values($this->filters());
        foreach ($columns as $c) { if (!$c instanceof Column) { throw new \InvalidArgumentException('A list column is a Column.'); } }
        foreach ($filters as $f) { if (!$f instanceof Filter) { throw new \InvalidArgumentException('A list filter is a Filter.'); } }
        if ($columns === []) { throw new \InvalidArgumentException('A list has at least one column.'); }
        return [$columns, $filters];
    }
    /** @param list<Column> $columns @param list<Filter> $filters */
    private function standardTable(Table $table, array $columns, array $filters, string $noMatch): Table
    {
        [$empty, $next] = $this->emptyLine();
        $bulk = array_values($this->bulkActions());
        foreach ($bulk as $action) { if (!$action instanceof BulkAction) { throw new \InvalidArgumentException('A bulk action is a native Filament bulk action.'); } }
        $built = array_map(static fn (Column $c) => $c->toColumn(), $columns);
        $hidden = array_values(array_map(static fn (Column $c): string => $c->name, array_filter($columns, static fn (Column $c): bool => $c->isOptional())));
        $table = TableColumns::choose(EmptyTable::apply($table, $empty, $next, $noMatch), $built, $hidden)
            ->filters(array_map(static fn (Filter $f) => $f->toFilter(), $filters))
            ->paginated([25, 50, 100])->defaultPaginationPageOption(25);
        if ($bulk !== []) { $table->toolbarActions([BulkActionGroup::make($bulk)->label('Selected')]); }
        $label = $this->searchLabel();
        return $label === null ? $table : $table->searchPlaceholder($label);
    }
}
