<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DemoTag, OneLine};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value;
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
    private ?\Closure $link = null;
    private bool $optional = false;
    private bool $short = false;
    private bool $stack = false;
    private bool $end = false;
    private bool $demo = false;
    private ?\Closure $demoOf = null;
    private ?\Closure $visibleWhen = null;
    private ?\Closure $act = null;
    private bool $tag = true;
    private function __construct(public readonly string $name, public readonly string $label, public readonly string $kind, public bool $searchable = false, public bool $sortable = false) {}
    /** Plain text that stays complete: the row's own field, or what $text returns for the row. @param ?\Closure(array|Model):?string $text */
    public static function text(string $name, string $label, ?\Closure $text = null): self { $c = new self($name, $label, 'text'); $c->text = $text; return $c; }
    /** A note: the step a row needs, that may shorten only when its linked record holds the full text (P-TBL-7). */
    public static function note(string $name, string $label, \Closure $text): self { $c = new self($name, $label, 'note'); $c->text = $text; return $c; }
    /** A domain name: one line that may shorten; its record link holds the full name. A demo record shows its Demo tag. */
    public static function domain(string $name, string $label, bool $demoTag = true): self { $c = new self($name, $label, 'domain'); $c->tag = $demoTag; return $c; }
    /** A value from the row, drawn by the Value piece; null leaves the cell empty. The cell's state is the value's word,
     * so a reader or a test reads the word the mark stands beside. @param \Closure(array|Model):?Value $value */
    public static function value(string $name, string $label, \Closure $value): self { $c = new self($name, $label, 'value'); $c->value = $value; return $c; }
    /** Search matches this column. On a saved-record list, $query narrows the query itself, for a column that is not a
     * plain field (a customer's name). @param ?\Closure(Builder,string):Builder $query */
    public function searchable(?\Closure $query = null): self { $this->searchable = true; $this->searchQuery = $query; return $this; }
    /** @param ?\Closure(Builder,string):Builder $query orders a saved-record list by something that is not a plain field;
     * it gets the query and 'asc' or 'desc' */
    public function sortable(?\Closure $query = null): self { $this->sortable = true; $this->sortQuery = $query; return $this; }
    /** Retained for callers using the old responsive API. Staff columns now stay visible at every width. */
    public function from(string $width): self
    {
        if (!in_array($width, self::FROM, true)) { throw new \InvalidArgumentException('A column shows from sm, md, lg or xl.'); }
        if ($this->stack) { throw new \InvalidArgumentException('A column that stacks on phones already shows from sm.'); }
        $this->from = $width; return $this;
    }
    /** Retained for callers using the old phone API. Staff tables now scroll with the same columns at every width. */
    public function stackOnPhone(): self
    {
        if ($this->from !== null) { throw new \InvalidArgumentException('A column that stacks on phones already shows from sm.'); }
        return $this;
    }
    public function stacksOnPhone(): bool { return $this->stack; }
    /** The words this column adds under the name on a phone: a value's word, or the text. Built by the list type only. */
    public function phoneText(array|Model $record): ?string
    {
        if ($this->visibleWhen !== null && !($this->visibleWhen)()) { return null; }
        $text = $this->kind === 'value' ? $this->valueOf($record)?->word : ($this->text === null ? data_get($record, $this->name) : ($this->text)($record));
        return is_scalar($text) && trim((string) $text) !== '' ? trim((string) $text) : null;
    }
    /** Where the cell opens, such as the request a note names; null opens the row's own link.
     * @param \Closure(array|Model):?string $link */
    public function link(\Closure $link): self { $this->link = $link; return $this; }
    /** A text cell that holds a person's name: it wraps instead of shortening. */
    public function short(): self { return $this; }
    /** A demo record shows its Demo tag after this plain text, as it does on a domain name. */
    /** The Demo tag beside the cell's first line when the record $of returns for the row is demo data, such as the account a TLD row belongs to. */
    public function demoOf(\Closure $of): self { $this->demoOf = $of; return $this; }
    public function demo(): self { $this->demo = true; return $this; }
    /** Money and counts: right aligned, tabular and never cut (P-TBL-9). */
    public function end(): self { $this->end = true; return $this; }
    /** Staff can show it from the Columns menu; it starts hidden. */
    /** Shown while $show says so, read on every render, such as a column only readers with a grant see. */
    public function visibleWhen(\Closure $show): self { $this->visibleWhen = $show; return $this; }
    /** A note that says what staff must do, marked amber on the rows where $act returns true. */
    public function actWhen(\Closure $act): self { $this->act = $act; return $this; }
    public function optional(): self { $this->optional = true; return $this; }
    public function isOptional(): bool { return $this->optional; }
    private function nameCell(TextColumn $column): TextColumn
    { $name = OneLine::apply($column->fontFamily(FontFamily::Mono), OneLine::NAME); return $this->tag ? DemoTag::apply($name) : $name; }
    private function noted(TextColumn $column): TextColumn
    { $act = $this->act; return $act === null ? $column : $column->extraAttributes(static fn (array|Model $record): array => \Paymenter\Extensions\Servers\Domains\Admin\Support\StatusMark::note((bool) $act($record)), merge: true); }
    private function tagged(TextColumn $column): TextColumn { return $this->demo ? DemoTag::apply($column) : $column; }
    private function valueOf(array|Model $record): ?Value
    {
        $value = ($this->value)($record);
        if ($value !== null && !$value instanceof Value) { throw new \UnexpectedValueException('A value column returns a Value or null.'); }
        return $this->demoOf === null ? $value : $value?->tagged(DemoTag::tag(($this->demoOf)($record)));
    }
    public function queries(): bool { return $this->searchQuery !== null || $this->sortQuery !== null; }
    /** Built by the list type only. */
    public function toColumn(): TextColumn
    {
        $column = TextColumn::make($this->name)->label($this->label);
        // Filament passes a query closure its arguments by name, so the page's closure is called with them in order.
        $sort = $this->sortQuery;$find = $this->searchQuery;
        $column->sortable($this->sortable, $sort === null ? null : static fn (Builder $query, string $direction): Builder => $sort($query, $direction));
        if ($this->searchable) { $column->searchable(query: $find === null ? null : static fn (Builder $query, string $search) => $find($query, $search)); }
        if ($this->visibleWhen !== null) { $show = $this->visibleWhen; $column->visible(static fn (): bool => (bool) $show()); }

        if ($this->link !== null) { $link = $this->link; $column->url(static fn (array|Model $record): ?string => $link($record)); }
        if ($this->end) { $column->alignEnd()->extraAttributes(['class' => 'domains-money domains-nowrap'], merge: true); }
        return match ($this->kind) {
            'domain' => $this->nameCell($column),
            'note' => $this->noted(OneLine::apply($column->color('gray')->state(fn (array|Model $record): ?string => ($this->text)($record)), OneLine::NOTE)),
            'value' => $column->state(fn (array|Model $record): ?string => $this->valueOf($record)?->word)
                // Our own escaped piece markup, passed as Htmlable: Filament's sanitizer would drop its data-* markers.
                ->formatStateUsing(fn (array|Model $record): HtmlString => new HtmlString((string) $this->valueOf($record)?->toHtml())),
            default => $this->tagged(OneLine::apply($this->text === null ? $column : $column->state(fn (array|Model $record): ?string => ($this->text)($record)),
                $this->short ? OneLine::SHORT : OneLine::LINE)),
        };
    }
}
