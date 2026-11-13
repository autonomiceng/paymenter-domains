<?php
// Adapted from Enom 444f31c ListDomains; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainDecisions, DomainFacts, DomainQueries, NavigationCount, OperationQueries, PaidUncertainty, Sheet, Staff, StaffDomainInputs, StaffTabs};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Row, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Column, Filter, RecordListPage, Section};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain};
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Missing, Plain, Reader, State};
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionCommit, ComplimentaryAccounts, ObservationWork};
/** Domains > Domains, a List page: every domain with its status, next step, customer, expiry, renewal and registrar. */
class ListDomains extends RecordListPage
{
    protected static string $resource = DomainResource::class;
    private const REGISTRARS = ['enom' => 'Enom', 'namesilo' => 'NameSilo'];
    /** One read of the Orders switches per request: the Status filter and every row's status use it, so they agree even if a
     * switch changes meanwhile. Protected, so never part of the Livewire snapshot. */
    protected ?array $orders = null;
    /** Renewal stops and sync state for the visible page, keyed by domain id: one batch of reads per page. */
    protected ?\WeakMap $rows = null;
    /** The list's Needs attention filter and the menu count name the same domains; opening the list refreshes the count. */
    public function mount(): void { parent::mount(); NavigationCount::forget('domains'); }
    public function getBreadcrumbs(): array { return []; }
    public function getSubNavigation(): array { return StaffTabs::domains('Domains'); }
    protected function head(): Head { return Head::make('Domains', Reader::Staff); }
    protected function actions(): array
    {
        return [Action::make('adopt')->label('Import domain')->url(DomainResource::getUrl('adopt'))
            ->visible(fn (): bool => config('domains.adoption.enabled', false) === true && collect(AdoptionCommit::PERMISSIONS)->every(fn ($permission) => Staff::can($permission)))];
    }
    /** Each held registrar account says what its paid orders wait for, with its request. A section starts with its one
     * notice, so each account gets its own section. */
    protected function notices(): array
    {
        return array_map(static fn (array $h): Section => Section::make('Paid orders on '.$h['name'], [Row::notice('paid-'.$h['server_id'],
            Value::state(State::UnderReview, Reader::Staff, PaidUncertainty::line($h)),
            $h['url'] ? Sheet::link('paid_request_'.$h['server_id'], $h['label'], $h['url']) : null)], null, 'paid-orders-'.$h['server_id']), PaidUncertainty::holds());
    }
    protected function query(): Builder { return parent::query()->with(['user:id,first_name,last_name,email', 'server:id,name', 'latestAcquisition']); }
    protected function columns(): array
    {
        return [
            Column::domain('domain', 'Domain')->searchable()->sortable(),
            Column::value('status', 'Status', fn (Domain $d): Value => Value::state($this->state($d), Reader::Staff, null, 'domain-'.$d->id)),
            // The step comes first, so a cut note still shows it. A step that names a request opens it.
            Column::note('note', 'Note', fn (Domain $d): ?string => $this->note($d)['text'])->link(fn (Domain $d): ?string => DomainFacts::requestUrl($this->status($d))),
            Column::text('customer', 'Customer', fn (Domain $d): ?string => $d->user ? (trim($d->user->first_name.' '.$d->user->last_name) ?: $d->user->email) : null)
                ->short()->searchable(fn (Builder $query, string $search): Builder => self::customerSearch($query, $search)),
            Column::value('provider_expires_at', 'Expires', fn (Domain $d): ?Value => $this->expires($d))
                ->sortable(fn (Builder $query, string $direction): Builder => $query->orderBy('provider_expires_at', $direction)),
            Column::value('auto_renew', 'Auto-renew', fn (Domain $d): ?Value => $this->renewal($d)),
            Column::value('server_id', 'Registrar', fn (Domain $d): Value => $d->server
                ? Value::fact(Fact::names([$d->server->name.' #'.$d->server_id]), self::REGISTRARS[$d->registrar_key] ?? null)
                : Value::state(State::NotConnected, Reader::Staff, DomainFacts::NO_ACCOUNT))->optional(),
        ];
    }
    protected function filters(): array
    {
        $accounts = Staff::can() ? \App\Models\Server::whereIn('id', Account::query()->pluck('server_id'))->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->mapWithKeys(fn ($server) => [$server->id => $server->name.' #'.$server->id])->all() : [];
        return [
            Filter::select('price_mode', 'Domain funding', ['standard' => 'Standard', 'complimentary' => ComplimentaryAccounts::LABEL]),
            Filter::select('status', 'Status', DomainResource::statusOptions())->multiple()->query(fn (Builder $query, array $data): Builder => DomainQueries::status($query, $data['values'] ?? [], $this->orders())),
            Filter::select('server_id', 'Registrar account', $accounts),
            Filter::select('expiring', 'Expires within', ['7' => '7 days', '30' => '30 days', '60' => '60 days', '90' => '90 days'])->query(function (Builder $query, array $data): Builder {
                $days = (int) ($data['value'] ?? 0);
                return in_array($days, [7, 30, 60, 90], true) ? $query->whereDate('provider_expires_at', '>=', CarbonImmutable::today())
                    ->whereDate('provider_expires_at', '<=', CarbonImmutable::today()->addDays($days)) : $query;
            }),
            // The account page's Domain alerts row links here with this filter and the account.
            Filter::toggle('alerts', 'Needs attention', fn (Builder $query): Builder => DomainQueries::alerting($query)),
        ];
    }
    protected function defaultSort(): ?array { return ['domain', 'asc']; }
    protected function searchLabel(): ?string { return 'Search domain or customer'; }
    protected function emptyLine(): array { return ['No domains yet', 'They show here after a customer orders one.', 'No domains match']; }
    private function orders(): array { return $this->orders ??= OperationQueries::ordersOffAccounts(); }
    /** The Orders switches are read once at boot, before the table is built or any other read, so a switch that changes
     * while the page is built can't split the filter from the rows. */
    public function boot(): void { $this->orders(); }
    /** Every word has to match the customer's name or email, so a full name finds them. */
    private static function customerSearch(Builder $query, string $search): Builder
    {
        return $query->whereHas('user', function (Builder $user) use ($search): void {
            foreach (preg_split('/\s+/', trim($search), flags: PREG_SPLIT_NO_EMPTY) as $word) {
                $user->where(fn (Builder $q) => $q->where('first_name', 'like', "%$word%")->orWhere('last_name', 'like', "%$word%")->orWhere('email', 'like', "%$word%"));
            }
        });
    }
    private function status(Domain $d): array { return DomainFacts::status($d, $this->row($d)['inputs']); }
    /** The status word follows the list tone: a row that would read Active but can't be synced reads Needs review. */
    private function state(Domain $d): State
    {
        $status = $this->status($d); $row = $this->row($d);
        // Sync stopped or no matching account: staff must look, and the note says what to fix.
        if (DomainFacts::listTone($status, $row['sync'], $row['noAccount']) !== $status['tone']) { return State::NeedsReview; }
        // On hold has no word of its own in the closed list: staff are reviewing it, and the note says who held it.
        return State::tryFrom((string) $status['key']) ?? State::UnderReview;
    }
    private function note(Domain $d): array { $row = $this->row($d); return DomainFacts::note($this->status($d), $row['sync'], $row['noAccount'], $d); }
    /** A registered domain without a saved expiry says why: it fills in by itself, or the read failed. */
    private function expires(Domain $d): ?Value
    {
        if (($day = Fact::day($d->provider_expires_at)) !== null) { return Value::fact($day); }
        if (!DomainFacts::registered($d)) { return null; }
        $cause = DomainFacts::unread($d, $this->row($d)['sync']);
        return Value::state(Missing::needsNothing($cause) ? State::NotReadYet : State::CouldntRead, Reader::Staff, $cause);
    }
    /** Renewal applies until the domain has ended, so pending and under-review rows show it too. A pending import bills nothing until import. */
    private function renewal(Domain $d): ?Value
    {
        if (DomainFacts::ended($d)) { return null; }
        $row = $this->row($d);
        if ($row['off'] === null) { return Value::state(State::CouldntRead, Reader::Staff, DomainFacts::UNREADABLE_RENEWAL); }
        if (!$d->adoptionPending() && !$row['off'] && !$row['accountOff']) { return Value::plain(Plain::On); }
        return Value::plain(Plain::Off, $d->adoptionPending() ? 'Until import' : (!$row['off'] ? 'Account renewals off' : null));
    }
    /** 'off': true when automatic renewal is off, null when its saved data cannot be read. 'accountOff': the account's Renewals
     * switch is off. 'sync': DomainFacts::sync(). 'noAccount': no registrar account matches. */
    private function row(Domain $record): array
    {
        $records = $this->getTableRecords();
        $this->rows ??= new \WeakMap();
        $cached = $this->rows[$records] ?? [];
        if (!array_key_exists($record->id, $cached)) {
            $page = collect(method_exists($records, 'items') ? $records->items() : $records->all())->filter(fn ($r) => $r instanceof Domain);
            if (!$page->contains('id', $record->id)) { $page = collect([$record]); }
            $accounts = Account::whereIn('server_id', $page->pluck('server_id')->unique()->values()->all())->get();
            $stopped = ObservationWork::stoppedIds($page);
            $inputs = StaffDomainInputs::for($page, $this->orders());
            $accountOff = [];
            foreach ($page as $d) {
                $account = $accounts->first(fn (Account $a) => (int) $a->server_id === (int) $d->server_id && $a->registrar_key === $d->registrar_key
                    && $a->account_fingerprint === $d->account_fingerprint);
                // One switch read per account on the page, by the domain page's rule.
                $byServer = $accounts->first(fn (Account $a) => (int) $a->server_id === (int) $d->server_id && $a->registrar_key === $d->registrar_key);
                $off = $d->billing_managed && $byServer ? ($accountOff[$byServer->id] ??= DomainDecisions::accountRenewalsOff($d, $byServer)) : false;
                $cached[$d->id] = ['inputs' => $inputs[$d->id], 'off' => $inputs[$d->id]['off'], 'accountOff' => $off, 'sync' => DomainFacts::sync($d, $account, isset($stopped[$d->id]), accountLoaded: true), 'noAccount' => $account === null];
            }
            $this->rows[$records] = $cached;
        }
        return $cached[$record->id];
    }
}
