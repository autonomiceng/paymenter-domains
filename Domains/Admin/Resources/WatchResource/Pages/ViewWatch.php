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
        $checks = $facts['Checks'];
        $facts['Checks'] = [];
        foreach (['Last check', 'Next check', 'Registry', 'Nameservers', 'DNS records'] as $label) {
            if (isset($checks[$label])) { $facts['Checks'][$label] = $checks[$label]; }
        }
        $facts['Checks'] += ($facts['Evidence'] ?? []) + $checks;
        unset($facts['Evidence']);
        $failed = ($checks['Last check']['tone'] ?? null) === 'danger';
        $sections = [];
        foreach ($facts as $title => $entries) {
            $rows = [];
            if ($title === 'Checks' && $failed) {
                $reason = $checks['Last check']['sub'];
                $kind = $checks['Last check']['retryKind'];
                $work = $this->getRecord()->work->firstWhere('kind',$kind);
                if ($this->getRecord()->state === 'active' && $work) {
                    $label = \Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::checkLabel($kind);
                    if ($work->backoff_until > time()) { $reason .= ' '.$label.' can retry after '.WatchFacts::date($work->backoff_until).'.'; }
                    elseif ($work->claim) { $reason .= ' '.$label.' is already running.'; }
                }
                $rows[] = Row::notice('failed-check', Value::state(State::Failed, Reader::Staff, $reason), $this->checkAction());
                $entries['Last check']['tone'] = $entries['Last check']['sub'] = null;
                if (!$entries['Last check']['value']) { unset($entries['Last check']); }
            }
            foreach ($entries as $label => $fact) {
                $change = $label === 'DNS template' ? WatchActions::changeTemplate($this->getRecord(), $this->revision) : null;
                $rows[] = WatchRows::row($label, $fact, $change);
            }
            // The head shows the state; its reason opens the sheet, so the page has no banner repeating it.
            // A stopped domain starts again from a monitored hosting product, so its notice links to that setting.
            $settings = $this->getRecord()->state === 'retired' && MonitoringSettings::canAccess() ? Sheet::link('monitoring_settings', 'Monitoring settings', WatchResource::getUrl('settings')) : null;
            if ($sections === [] && ($status['reason'] ?? null) !== null) { array_unshift($rows, WatchRows::notice($status['label'], $status['reason'], $settings)); }
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
