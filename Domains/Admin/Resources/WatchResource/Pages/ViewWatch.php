<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Pages\Enums\SubNavigationPosition;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Sheet, WatchActions, WatchFacts, WatchPage, WatchRows};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{RecordDetailPage, Section};
use Paymenter\Extensions\Servers\Domains\Presentation\{Reader, State};
/** A monitored domain: its facts and latest checks in the sheet, the newest history on the rail. */
class ViewWatch extends RecordDetailPage
{
    use WatchPage;
    protected static string $resource = WatchResource::class;
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
    protected function sections(): array
    {
        ['facts' => $facts, 'status' => $status] = $this->watchFacts();
        // Each check time that isn't saved yet gets its own Check now.
        $check = $this->getAction('check', false)?->isVisible() ? fn () => WatchActions::check($this->getRecord(), $this->revision) : null;
        $sections = [];
        foreach ($facts as $title => $rows) {
            $rows = array_map(function (string $label) use ($rows, $check): Row {
                if ($label === 'Expected nameservers' && $rows[$label]['value'] === null) {
                    return Row::make($label, Value::state(State::NotSet, Reader::Staff))
                        ->actions(WatchActions::changeTemplate($this->getRecord(), $this->revision)->name('changeExpectedNameservers')->label('Change')->link());
                }
                return WatchRows::row($label, $rows[$label], $check);
            }, array_keys($rows));
            // The head shows the state; its reason opens the sheet, so the page has no banner repeating it.
            if ($sections === [] && ($status['reason'] ?? null) !== null) { array_unshift($rows, WatchRows::notice($status['label'], $status['reason'])); }
            $sections[] = Section::make($title === 'Checks' ? 'Latest checks' : $title, $rows);
        }
        return $sections;
    }
    protected function rail(): array
    {
        $rows = WatchFacts::history($this->getRecord());
        if ($rows === []) { return [Section::make('Activity', [], 'No history yet.')]; }
        if ($rows === null) { return [Section::make('Activity', [Row::notice('history', Value::state(State::CouldntRead, Reader::Staff, WatchFacts::DECRYPT))])]; }
        return [Section::make('Activity', [WatchRows::trace(array_slice($rows, 0, Sheet::RAIL_STEPS))])
            ->link(Sheet::link('view_history', 'View all '.count($rows), WatchResource::getUrl('history', ['record' => $this->getRecord()])))];
    }
}
