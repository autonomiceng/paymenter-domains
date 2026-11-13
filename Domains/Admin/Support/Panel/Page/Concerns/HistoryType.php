<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Concerns;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Paymenter\Extensions\Servers\Domains\Admin\Support\HistoryTable;
/** What happened to a record, newest first: the head, then the history table (Time, Change, Detail, By) with search
 * and a By filter. The page supplies the events; the table, its columns and its empty line belong to the type. */
trait HistoryType
{
    use RendersType;
    use InteractsWithTable;
    /** Newest first. Null when the saved history can't be read. `time` is an ISO 8601 instant or null; `url`, when
     * set, opens the event's request. @return ?list<array{time:?string,change:string,reason:string,by:string,url?:string}> */
    abstract protected function events(): ?array;
    /** Above the table, only while something needs saying: each section opens with a notice, as on a list.
     * @return list<\Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Section> */
    protected function notices(): array { return []; }
    final public function content(Schema $schema): Schema { return $schema->components([...self::noticesAbove($this->notices(), 'Above a history', 'history-notices'), EmbeddedTable::make()]); }
    final public function table(Table $table): Table
    {
        return HistoryTable::apply($table, function (): ?array {
            $events = $this->events();
            if ($events === null) { return null; }
            foreach ($events as $event) {
                if (!is_array($event) || !is_string($event['change'] ?? null) || !array_key_exists('by', $event)) { throw new \InvalidArgumentException('An event has its change and who did it.'); }
            }
            return array_values($events);
        });
    }
}
