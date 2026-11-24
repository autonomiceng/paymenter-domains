<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Concerns;
use Filament\Schemas\Schema;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Partial, Row, SettingsRow};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Section;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Sheet;
/** Values someone chooses: the head, then one column of sections whose rows are all settings. No rail, no trace. Each
 * row says who sets it, so a reader with no control still learns who can change it. A section may open with a notice.
 * A settings row with its own dialog is drawn by its partial. */
trait SettingsType
{
    use RendersType;
    /** @return list<Section> */
    abstract protected function sections(): array;
    final public function content(Schema $schema): Schema
    {
        $sections = self::sectionsOnly($this->sections(), 'A settings page');
        foreach ($sections as $section) {
            foreach ($section->rows as $row) {
                if (!$row instanceof SettingsRow && !$row instanceof Partial && !($row instanceof Row && Section::isNotice($row))) { throw new \InvalidArgumentException('A settings page holds settings rows and notices only.'); }
            }
        }
        // No rail, so the sheet takes the full width, as the other tabs and lists do, with no empty rail space beside it.
        return $schema->components([Sheet::single(array_map(static fn (Section $s) => $s->toSchema(), $sections), 'sheet')]);
    }
}
