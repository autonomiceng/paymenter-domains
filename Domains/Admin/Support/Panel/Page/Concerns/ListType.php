<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Concerns;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Paymenter\Extensions\Servers\Domains\Admin\Support\EmptyTable;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Column, Filter};
/** Records to pick from: the head, then the standard table. The page supplies columns, filters, rows and the empty
 * line as data; search, sort, paging, the empty state and the row link belong to the type. */
trait ListType
{
    use RendersType;
    use InteractsWithTable;
    /** @return list<Column> */
    abstract protected function columns(): array;
    /** @return list<Filter> */
    protected function filters(): array { return []; }
    /** Every row as an array of its column values, plus `url` when the row opens a record. @return list<array<string,mixed>> */
    abstract protected function rows(): array;
    /** What fills the list when it is empty and the next step: ['No monitored domains yet.', 'Add a domain to start checking it.']
     * @return array{0:string,1:string} */
    abstract protected function emptyLine(): array;
    final public function content(Schema $schema): Schema { return $schema->components([EmbeddedTable::make()]); }
    final public function table(Table $table): Table
    {
        $columns = array_values($this->columns());$filters = array_values($this->filters());
        foreach ($columns as $c) { if (!$c instanceof Column) { throw new \InvalidArgumentException('A list column is a Column.'); } }
        foreach ($filters as $f) { if (!$f instanceof Filter) { throw new \InvalidArgumentException('A list filter is a Filter.'); } }
        if ($columns === []) { throw new \InvalidArgumentException('A list has at least one column.'); }
        [$empty, $next] = $this->emptyLine();
        $searchable = array_values(array_map(static fn (Column $c): string => $c->name, array_filter($columns, static fn (Column $c): bool => $c->searchable)));
        $table = EmptyTable::apply($table, $empty, $next, 'No rows match.')
            ->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, array $filters, int $page, int $recordsPerPage) use ($searchable): LengthAwarePaginator {
                $rows = collect($this->rows())
                    ->filter(static fn (array $r): bool => blank($search) || Str::contains(implode(' ', array_map(static fn (string $k): string => (string) ($r[$k] ?? ''), $searchable)), $search, true))
                    ->filter(static function (array $r) use ($filters): bool {
                        foreach ($filters as $name => $state) { if (filled($state['value'] ?? null) && (string) ($r[$name] ?? '') !== (string) $state['value']) { return false; } }
                        return true;
                    });
                if ($sortColumn !== null) { $rows = $rows->sortBy(static fn (array $r): string => (string) ($r[$sortColumn] ?? ''), SORT_NATURAL | SORT_FLAG_CASE, $sortDirection === 'desc'); }
                return new LengthAwarePaginator($rows->values()->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
            })
            ->columns(array_map(static fn (Column $c) => $c->toColumn(), $columns))
            ->filters(array_map(fn (Filter $f): SelectFilter => SelectFilter::make($f->name)->label($f->label)->options($f->options), $filters))
            ->recordUrl(static fn (array $record): ?string => $record['url'] ?? null)
            ->paginated([25, 50, 100])->defaultPaginationPageOption(25);
        return $searchable === [] ? $table : $table->searchable();
    }
}
