<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page;
use Filament\Actions\Action;
use Filament\Schemas\Components\Section as FilamentSection;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Partial, Row, Trace};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Sheet;
/** One titled section of a page: rows (Row, SettingsRow, and on a record Detail page a Partial) or one Trace, an optional sentence under the title, and at
 * most one link beside it ("View all 12"). Data only: the page type decides where it goes and how it draws.
 * A notice, when there is one, is the first row. */
final class Section
{
    /** @param list<Row|Trace|Partial> $rows */
    private function __construct(public readonly string $title, public readonly array $rows, public readonly ?string $note, public readonly string $key, public readonly ?Action $link) {}
    /** @param list<Row|Trace|Partial> $rows */
    public static function make(string $title, array $rows, ?string $note = null, ?string $key = null): self
    {
        if (trim($title) === '') { throw new \InvalidArgumentException('A section needs its title.'); }
        if ($note !== null && (trim($note) === '' || !str_ends_with(trim($note), '.'))) { throw new \InvalidArgumentException('A section note is one sentence.'); }
        $rows = array_values($rows);
        foreach ($rows as $i => $row) {
            if (!$row instanceof Row && !$row instanceof Trace && !$row instanceof Partial) { throw new \InvalidArgumentException('A section holds rows, partials or a trace, nothing else.'); }
            if ($row instanceof Trace && count($rows) > 1) { throw new \InvalidArgumentException('A trace fills its section alone.'); }
            if ($i > 0 && $row instanceof Row && self::isNotice($row)) { throw new \InvalidArgumentException('A notice is the first row of its section.'); }
        }
        return new self($title, $rows, $note, $key ?? 'sec-'.(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: substr(md5($title), 0, 8)), null);
    }
    /** The one link beside the title, such as "View all 12" on an Activity rail. */
    public function link(Action $link): self { return new self($this->title, $this->rows, $this->note, $this->key, $link); }
    public function hasTrace(): bool { return ($this->rows[0] ?? null) instanceof Trace; }
    public function hasPartial(): bool { return array_filter($this->rows, static fn ($row): bool => $row instanceof Partial) !== []; }
    public static function isNotice(Row $row): bool { return str_starts_with((string) $row->getKey(false), 'notice-'); }
    /** Built by the page type only. */
    public function toSchema(): FilamentSection
    { return Sheet::section($this->title, $this->rows, $this->note, $this->key, $this->link === null ? [] : [$this->link]); }
}
