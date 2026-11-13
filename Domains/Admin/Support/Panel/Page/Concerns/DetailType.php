<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Concerns;
use Filament\Schemas\Schema;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{RecordDetailPage, Section};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Sheet;
/** A record page: the head, then the sheet of sections, with the rail beside it (under it on a phone). */
trait DetailType
{
    use RendersType;
    /** The sheet, top to bottom. @return list<Section> */
    abstract protected function sections(): array;
    /** Beside the sheet: activity, or a few facts about the account. @return list<Section> */
    protected function rail(): array { return []; }
    final public function content(Schema $schema): Schema
    {
        $record = $this instanceof RecordDetailPage;
        $sections = self::sectionsOnly($this->sections(), 'The sheet');
        $railSections = self::sectionsOnly($this->rail(), 'The rail');
        foreach ([...$sections, ...$railSections] as $section) {
            if ($section->hasPartial() && !$record) { throw new \InvalidArgumentException('A partial view belongs on a record Detail page.'); }
        }
        $split = Sheet::split(array_map(static fn (Section $s) => $s->toSchema(), $sections), array_map(static fn (Section $s) => $s->toSchema(), $railSections), 'sheet');
        return $schema->components([$record ? $this->withRelations($split) : $split]);
    }
}
