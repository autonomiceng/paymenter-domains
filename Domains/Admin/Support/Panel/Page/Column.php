<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page;
use Filament\Tables\Columns\TextColumn;
use Paymenter\Extensions\Servers\Domains\Admin\Support\OneLine;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainName;
/** One list column, as data: plain text, a domain name, or a panel Value (a state with its mark, a plain word or a
 * fact). The list type builds the Filament column, so a page can't add classes, colours or markup to a cell. */
final class Column
{
    private ?\Closure $value = null;
    private function __construct(public readonly string $name, public readonly string $label, public readonly string $kind, public bool $searchable = false, public bool $sortable = false) {}
    public static function text(string $name, string $label): self { return new self($name, $label, 'text'); }
    /** A domain name: one line, cut with "..." and the full name on hover and focus. */
    public static function domain(string $name, string $label): self { return new self($name, $label, 'domain'); }
    /** A value from the row, drawn by the Value piece. @param \Closure(array):Value $value */
    public static function value(string $name, string $label, \Closure $value): self { $c = new self($name, $label, 'value'); $c->value = $value; return $c; }
    public function searchable(): self { $this->searchable = true; return $this; }
    public function sortable(): self { $this->sortable = true; return $this; }
    /** Built by the list type only. */
    public function toColumn(): TextColumn
    {
        $column = TextColumn::make($this->name)->label($this->label)->sortable($this->sortable);
        return match ($this->kind) {
            'domain' => OneLine::apply($column->formatStateUsing(static fn (string $state) => DomainName::html($state))),
            'value' => $column->state(fn (array $record): string => (function () use ($record): string {
                $value = ($this->value)($record);
                if (!$value instanceof Value) { throw new \UnexpectedValueException('A value column returns a Value.'); }
                return $value->toHtml();
            })())->html(),
            default => $column->wrap()->lineClamp(2),
        };
    }
}
