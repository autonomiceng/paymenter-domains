<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Concerns;
use Filament\Actions\{Action, ActionGroup, BulkAction, BulkActionGroup};
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{EmptyTable, Sheet, TableColumns};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Column, Filter, Section};
/** What both list types share: the head, any notices, then the standard table. The page supplies notices, columns,
 * filters, the empty line and its bulk actions as data; search, sort, paging, the empty state, the column menu and the row link belong to
 * the type. */
trait BuildsList
{
    use RendersType;
    use InteractsWithTable;
    /** @return list<Column> */
    abstract protected function columns(): array;
    /** @return list<Filter> */
    protected function filters(): array { return []; }
    /** What fills the list when it is empty and the next step, then what a search or filter that hid every row says:
     * ['No monitored domains', 'Turn on monitoring on a hosting product.', 'No monitored domains match']. Without the
     * third, the list type's own line shows. @return array{0:string,1:string,2?:string} */
    abstract protected function emptyLine(): array;
    /** Native bulk actions on the rows staff tick. Each checks its own permission when it runs. @return list<BulkAction> */
    protected function bulkActions(): array { return []; }
    /** Native actions at the end of each row: at most two, then a menu (P-ACT-9). Each checks its own permission and
     * gets the row. @return list<Action|ActionGroup> */
    protected function recordActions(): array { return []; }
    /** The one next step an empty list offers, as a native action; the page decides when it shows. */
    protected function emptyAction(): ?Action { return null; }
    /** The search box's words, when the list is searchable. */
    protected function searchLabel(): ?string { return null; }
    /** Above the table, rows that say where the list stands, such as the default the templates below it feed. Each
     * section holds rows (Row, SettingsRow). @return list<Section> */
    protected function summary(): array { return []; }
    /** Above the table, only while something needs saying: each section opens with a notice, or holds the steps it
     * asks for as a trace. @return list<Section> */
    protected function notices(): array { return []; }
    final public function content(Schema $schema): Schema
    {
        $notices = self::sectionsOnly($this->notices(), 'Above a list');
        foreach ($notices as $section) {
            $first = $section->rows[0] ?? null;
            if (!$section->hasTrace() && !($first instanceof \Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Row && Section::isNotice($first))) { throw new \InvalidArgumentException('A section above a list opens with a notice or holds steps.'); }
        }
        $summary = self::sectionsOnly($this->summary(), 'The summary above a list');
        foreach ($summary as $section) { if ($section->hasTrace() || $section->hasPartial()) { throw new \InvalidArgumentException('The summary above a list holds rows only.'); } }
        return $schema->components([
            ...($summary === [] ? [] : [Sheet::single(array_map(static fn (Section $s) => $s->toSchema(), $summary), 'list-summary')]),
            ...($notices === [] ? [] : [Sheet::single(array_map(static fn (Section $s) => $s->toSchema(), $notices), 'list-notices')]),
            EmbeddedTable::make(),
        ]);
    }
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
        // The no-match line is read when the table draws, so a filter applied after the table was built names itself.
        [$empty, $next] = $this->emptyLine();$fallback = $noMatch;$noMatch = fn (): string => $this->emptyLine()[2] ?? $fallback;
        $bulk = array_values($this->bulkActions());
        foreach ($bulk as $action) { if (!$action instanceof BulkAction) { throw new \InvalidArgumentException('A bulk action is a native Filament bulk action.'); } }
        $built = array_map(static fn (Column $c) => $c->toColumn(), $columns);
        $hidden = array_values(array_map(static fn (Column $c): string => $c->name, array_filter($columns, static fn (Column $c): bool => $c->isOptional())));
        $table = TableColumns::choose(EmptyTable::apply($table, $empty, $next, $noMatch), $built, $hidden)
            ->filters(array_map(static fn (Filter $f) => $f->toFilter(), $filters))
            ->paginated([25, 50, 100])->defaultPaginationPageOption(25);
        $row = array_values($this->recordActions());
        foreach ($row as $action) { if (!$action instanceof Action && !$action instanceof ActionGroup) { throw new \InvalidArgumentException('A row action is a native Filament action.'); } }
        if (count($row) > 2) { throw new \InvalidArgumentException('A row has at most two actions; the rest go in an ActionGroup.'); }
        if ($row !== []) { $table->recordActions($row); }
        if (($next = $this->emptyAction()) !== null) { $table->emptyStateActions([$next]); }
        if ($bulk !== []) { $table->toolbarActions([BulkActionGroup::make($bulk)->label('Selected')]); }
        $label = $this->searchLabel();
        return $label === null ? $table : $table->searchPlaceholder($label);
    }
}
