<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\WatchHistory;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Head;
use Paymenter\Extensions\Servers\Domains\Models\{WatchAudit, WatchDnsObservation, WatchRegistryObservation};
use Paymenter\Extensions\Servers\Domains\Presentation\{Reader, State};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** What a monitored domain's Overview and History tabs share: the head, the header actions, the tabs, and the wait for
 * a check staff asked for. Data only; the page types draw it. */
trait WatchPage
{
    /** The revision this page shows; writes from a stale page are refused. */
    #[Locked] public int $revision = 0;
    #[Locked] public array $pendingCheck = [];
    protected ?array $resolved = null;
    public function mount(int|string $record): void { parent::mount($record); $this->revision = (int) $this->getRecord()->revision; $this->waitForCheck(); }
    public function waitForCheck(): void
    {
        $w = $this->getRecord();$at = WatchAudit::where('subject_type', 'watch')->where('subject_id', $w->id)->where('event', 'staff_read_requested')->orderByDesc('id')->first(['created_at', 'time_zone'])?->created_at?->getTimestamp();
        $this->pendingCheck = [];if ($w->state !== 'active' || !$at) { return; }
        $before = max((int) WatchDnsObservation::where('watch_id', $w->id)->where('completed_at', '<', $at)->max('started_at'),
            (int) WatchRegistryObservation::where('watch_id', $w->id)->where('completed_at', '<', $at)->max('started_at'));
        if ($this->checkTime() <= $before) { $this->pendingCheck = [$at + 360, $before]; }
        if ($this->pendingCheck && now()->timestamp >= $this->pendingCheck[0]) { $this->pollCheck(); }
    }
    private function checkTime(): int { $w = $this->getRecord(); return max((int) $w->delegation?->started_at, (int) $w->points?->started_at, (int) $w->registry?->started_at); }
    /** Refresh saved facts only. Keep the mounted revision so stale actions still refuse. */
    public function refreshFacts(): void { $this->getRecord()->refresh(); $this->resolved = null; }
    public function pollCheck(): void
    {
        if (!$this->pendingCheck) { return; }
        $this->refreshFacts();[$until, $before] = $this->pendingCheck;
        if ($this->checkTime() > $before) { Notification::make()->success()->title('Check finished')->send(); }
        elseif (now()->timestamp >= $until) { Notification::make()->warning()->title('Check is taking longer')->body('Last check has not changed. Checks keep retrying. Open Registrars and check the background worker.')->persistent()->send(); }
        else { return; }
        $this->pendingCheck = [];
    }
    /** Facts and status, read once per request. */
    protected function watchFacts(): array
    {
        if (!$this->resolved) {
            $w = $this->getRecord();$row = $w->state === 'active' ? WatchFindings::summaries([$w])[$w->id] : null;$facts = WatchFacts::for($w, $row);
            $status = WatchFindings::status($w, $row['keys'] ?? [], ($row['summary'] ?? null)?->expiry, summary: $row['summary'] ?? null);
            $ended = match ($w->state) { 'retired' => WatchFindings::stoppedReason($w), 'paused' => WatchFindings::pausedReason($w), default => null };
            $this->resolved = ['facts' => $facts, 'status' => [...$status, 'reason' => $status['reason'] ?? $ended]];
        }
        return $this->resolved;
    }
    protected function head(): Head
    {
        $w = $this->getRecord();
        return Head::make($w->canonical_name, Reader::Staff)->domain()->tag(DemoData::is($w), false)->state(match ($w->state) { 'active' => State::Monitored, 'paused' => State::Paused, default => State::Stopped }, 'watch-'.$w->id);
    }
    protected function actions(): array { return WatchActions::watch($this->getRecord(), $this->revision); }
    protected function poll(): ?array { return $this->pendingCheck ? ['pollCheck', 5] : ($this->getRecord()->state === 'active' ? ['refreshFacts', 60] : null); }
    /** @return list<NavigationItem> */
    public function getSubNavigation(): array
    {
        $id = ['record' => $this->getRecord()];$history = $this instanceof WatchHistory;
        return [NavigationItem::make('Overview')->url(WatchResource::getUrl('view', $id))->isActiveWhen(fn () => !$history),
            NavigationItem::make('History')->url(WatchResource::getUrl('history', $id))->isActiveWhen(fn () => $history)];
    }
    public function getBreadcrumbs(): array { return [WatchResource::getUrl() => 'Monitored domains', $this->getRecord()->canonical_name]; }
    /** Check now next to a check time that isn't saved yet; mounted as a page action. */
    public function checkAction(): Action { return WatchActions::check($this->getRecord(), $this->revision); }
}
