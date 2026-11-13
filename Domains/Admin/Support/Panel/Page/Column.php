<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\{Builder, Model};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DemoTag, OneLine};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainName;
/** One list column, as data: plain text, a domain name, or a panel Value (a state with its mark, a plain word or a
 * fact). The list type builds the Filament column, so a page can't add classes, colours or markup to a cell.
 * A row is an array (ListPage) or a saved record (RecordListPage, QueryListPage); value and text closures get it. */
final class Column
{
    public const FROM = ['sm', 'md', 'lg', 'xl'];
    private ?\Closure $value = null;
    private ?\Closure $text = null;
    private ?\Closure $searchQuery = null;
    private ?\Closure $sortQuery = null;
    private ?string $from = null;
    private bool $optional = false;
    private function __construct(public readonly string $name, public readonly string $label, public readonly string $kind, public bool $searchable = false, public bool $sortable = false) {}
    /** Plain text: the row's own field, or what $text returns for the row. @param ?\Closure(array|Model):?string $text */
    public static function text(string $name, string $label, ?\Closure $text = null): self { $c = new self($name, $label, 'text'); $c->text = $text; return $c; }
    /** A domain name: one line, cut with "..." and the full name on hover and focus. A demo record shows its Demo tag. */
    public static function domain(string $name, string $label): self { return new self($name, $label, 'domain'); }
    /** A value from the row, drawn by the Value piece. @param \Closure(array|Model):Value $value */
    public static function value(string $name, string $label, \Closure $value): self { $c = new self($name, $label, 'value'); $c->value = $value; return $c; }
    /** Search matches this column. On a saved-record list, $query narrows the query itself, for a column that is not a
     * plain field (a customer's name). @param ?\Closure(Builder,string):Builder $query */
    public function searchable(?\Closure $query = null): self { $this->searchable = true; $this->searchQuery = $query; return $this; }
    /** @param ?\Closure(Builder,string):Builder $query orders a saved-record list by something that is not a plain field;
     * it gets the query and 'asc' or 'desc' */
    public function sortable(?\Closure $query = null): self { $this->sortable = true; $this->sortQuery = $query; return $this; }
    /** The column shows from this width up; a narrower screen leaves it out. */
    public function from(string $width): self
    {
        if (!in_array($width, self::FROM, true)) { throw new \InvalidArgumentException('A column shows from sm, md, lg or xl.'); }
        $this->from = $width; return $this;
    }
    /** Staff can show it from the Columns menu; it starts hidden. */
    public function optional(): self { $this->optional = true; return $this; }
    public function isOptional(): bool { return $this->optional; }
    public function queries(): bool { return $this->searchQuery !== null || $this->sortQuery !== null; }
    /** Built by the list type only. */
    public function toColumn(): TextColumn
    {
        $column = TextColumn::make($this->name)->label($this->label);
        // Filament passes a query closure its arguments by name, so the page's closure is called with them in order.
        $sort = $this->sortQuery;$find = $this->searchQuery;
        $column->sortable($this->sortable, $sort === null ? null : static fn (Builder $query, string $direction): Builder => $sort($query, $direction));
        if ($this->searchable) { $column->searchable(query: $find === null ? null : static fn (Builder $query, string $search) => $find($query, $search)); }
        if ($this->from !== null) { $column->visibleFrom($this->from); }
        return match ($this->kind) {
            'domain' => DemoTag::apply(OneLine::apply($column->formatStateUsing(static fn (string $state) => DomainName::html($state)), OneLine::NAME)),
            'value' => $column->state(fn (array|Model $record): string => (function () use ($record): string {
                $value = ($this->value)($record);
                if (!$value instanceof Value) { throw new \UnexpectedValueException('A value column returns a Value.'); }
                return $value->toHtml();
            })())->html(),
            default => ($this->text === null ? $column : $column->state(fn (array|Model $record): ?string => ($this->text)($record)))->wrap()->lineClamp(2),
        };
    }
}
