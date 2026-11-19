<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
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
    public function waitForCheck(?string $retryKind = null): void
    {
        $w = $this->getRecord();
        $request = WatchAudit::where('subject_type','watch')->where('subject_id',$w->id)
            ->where('event','staff_read_requested')->latest('id')->first(['created_at','time_zone','data']);
        $this->pendingCheck = [];
        if ($w->state !== 'active' || !$request) { return; }
        $at = $request->created_at->getTimestamp();
        try { $data = $request->data; }
        catch (\Illuminate\Contracts\Encryption\DecryptException) { return; }
        if ($retryKind !== null && ($data['retry_kind'] ?? null) !== $retryKind) { return; }
        if (isset($data['retry_kind'])) {
            $this->pendingCheck = [$at+360, $data['after_id'], $data['retry_kind'], $data['backoff_until']];
            if ($this->retryOutcome() !== null) { $this->pendingCheck = []; }
            elseif (now()->timestamp >= $at+360) { $this->pollCheck(); }
            return;
        }
        $before = max((int) WatchDnsObservation::where('watch_id', $w->id)->where('completed_at', '<', $at)->max('started_at'),
            (int) WatchRegistryObservation::where('watch_id', $w->id)->where('completed_at', '<', $at)->max('started_at'));
        if ($this->checkTime() <= $before) { $this->pendingCheck = [$at + 360, $before]; }
        if ($this->pendingCheck && now()->timestamp >= $this->pendingCheck[0]) { $this->pollCheck(); }
    }
    private function checkTime(): int { $w = $this->getRecord(); return max((int) $w->delegation?->started_at, (int) $w->points?->started_at, (int) $w->registry?->started_at); }
    /** A targeted retry finishes only on evidence from its own work row or observation stream. */
    private function retryOutcome(): ?string
    {
        [, $after, $kind, $backoff] = $this->pendingCheck;
        $w = $this->getRecord();
        $observation = $w->{$kind === 'expiry' ? 'registry' : $kind};
        $work = $w->work->firstWhere('kind',$kind);
        if (($work?->backoff_until ?? 0) > $backoff) { return 'failed'; }
        if (!$observation || $observation->id <= $after) { return null; }
        if ($kind === 'expiry') {
            return in_array($observation->result,['facts','not_registered'],true) || $observation->reason === 'no_coverage'
                ? 'finished' : 'failed';
        }
        if ($observation->safe_code === 'rotation') { return 'rotation'; }
        return $observation->result === 'complete' || $observation->safe_code === 'nameservers_missing' ? 'finished' : 'failed';
    }
    /** Refresh saved facts only. Keep the mounted revision so stale actions still refuse. */
    public function refreshFacts(): void { $this->getRecord()->refresh(); $this->resolved = null; }
    public function pollCheck(): void
    {
        if (!$this->pendingCheck) { return; }
        $this->refreshFacts();[$until, $before] = $this->pendingCheck;
        $kind = $this->pendingCheck[2] ?? null;
        if ($kind !== null) {
            $outcome = $this->retryOutcome();
            $label = Writes::checkLabel($kind);
            if ($outcome === 'finished') { Notification::make()->success()->title($label.' finished')->send(); }
            elseif ($outcome === 'failed') { Notification::make()->warning()->title($label.' failed')->send(); }
            elseif ($outcome === 'rotation') {
                Notification::make()->info()->title($label.' finished with partial results')->body('Checks continue automatically.')->send();
            }
            elseif (now()->timestamp >= $until) { Notification::make()->warning()->title($label.' is taking longer')->send(); }
            else { return; }
        }
        elseif ($this->checkTime() > $before) { Notification::make()->success()->title('Check finished')->send(); }
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
        $head = Head::make($w->canonical_name, Reader::Staff)->domain()->tag(DemoData::is($w), false)->state(match ($w->state) { 'active' => State::Monitored, 'paused' => State::Paused, default => State::Stopped }, 'watch-'.$w->id);
        if ($w->state === 'active') {
            $facts = $this->watchFacts()['facts'];
            $review = (int) (($facts['Domain']['DNS template']['tone'] ?? null) === 'warning' || $facts['Domain']['DNS template']['value'] === null)
                + (int) (\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::watchExpected($w) === null);
            $last = $facts['Checks']['Last check'] ?? [];
            $summary = $review ? [$review === 1 ? 'One setting needs review.' : "$review settings need review."] : [];
            if (($last['tone'] ?? null) === 'danger') {
                $summary[] = ($last['failedAt'] ?? null) ? 'Last check failed '.WatchFacts::date($last['failedAt']).'.' : 'A check failed.';
            }
            if ($summary) { $head->note(implode(' ', $summary)); }
        }
        return $head;
    }
    protected function actions(): array {
        $actions = WatchActions::watch($this->getRecord(), $this->revision);
        if ($this instanceof WatchHistory || ($this->watchFacts()['facts']['Checks']['Last check']['tone'] ?? null) !== 'danger') {
            array_unshift($actions, $this->checkAction());
        }
        return $actions;
    }
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
    public function checkAction(): Action { return WatchActions::check($this->getRecord(), $this->revision, $this->watchFacts()['facts']['Checks']['Last check']['retryKind'] ?? null); }
}
