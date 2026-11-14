<?php
// Adapted from Enom 444f31c ListOperations; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages;
use Filament\Pages\Enums\SubNavigationPosition;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels, OperationQueries, RequestFacts, Staff, StaffTabs};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Column, Filter, RecordListPage};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Reader, State};
/** Registrars > Registrar requests, a List page: every request with its status, note and registrar account. */
class ListOperations extends RecordListPage
{
    protected static string $resource = OperationResource::class;
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
    /** Request-scoped reads of the Orders switches and this reader's grants, so the Status filter and every row's word agree. Protected, so never part of the Livewire snapshot. */
    protected ?array $read = null;
    public function getSubNavigation(): array { return StaffTabs::registrars('Registrar requests'); }
    protected function head(): Head { Staff::requireView(); return Head::make('Registrars', Reader::Staff); }
    public function getBreadcrumbs(): array { return []; }
    private function grants(): \Paymenter\Extensions\Servers\Domains\Admin\Support\StaffGrants { return $this->read['grants'] ??= Staff::requireView(); }
    /** Null means too many accounts to list; it is kept as the answer so the snapshot is read once. */
    private function off(): ?array
    {
        if (! array_key_exists('off', $this->read ?? [])) { $this->read['off'] = OperationQueries::ordersOffAccounts(); }
        return $this->read['off'];
    }
    private function status(Operation $o): array
    { return RequestFacts::status($o, OperationQueries::ordersOff($o, $this->off()), grants: $this->grants(), accounts: $this->off()); }
    protected function query(): Builder { return OperationResource::getEloquentQuery(); }
    protected function rowUrl(\Illuminate\Database\Eloquent\Model $record): ?string { return OperationResource::getUrl('view', ['record' => $record]); }
    protected function columns(): array
    {
        $servers = \App\Models\Server::pluck('name', 'id')->all();
        return [
            Column::text('id', 'Request', fn (Operation $o): string => 'Request #'.$o->id)->sortable()->demo(),
            // Status sits beside the request number so a 390px phone shows both before the table scrolls.
            Column::value('state', 'Status', fn (Operation $o): Value => Value::state(RequestFacts::state($this->status($o)), Reader::Staff, null, 'request-'.$o->id)),
            Column::domain('domain.domain', 'Domain', false)->searchable(),
            Column::text('action', 'Type', fn (Operation $o): string => Labels::action($o->action)),
            Column::note('note', 'Note', fn (Operation $o): ?string => RequestFacts::reason($o, OperationQueries::ordersOff($o, $this->off()), $this->grants(), $this->off())),
            Column::value('created_at', 'Created', fn (Operation $o): Value => Value::fact(Fact::date($o->created_at->getTimestamp(), Reader::Staff)))->sortable()->from('lg'),
            // The account starts under Columns so the default columns fit inside the card at 1440px; the request page and the filter name it.
            Column::value('server_id', 'Registrar account', static fn (Operation $o): Value => isset($servers[$o->server_id])
                ? Value::fact(Fact::names([$servers[$o->server_id]]))
                : Value::state(State::NotConnected, Reader::Staff, 'Registrar account not found. Check Registrars.'))->from('xl')->optional(),
        ];
    }
    protected function filters(): array
    {
        // On hold reads Under review, so one word picks both.
        $words = [];
        foreach (array_keys(RequestFacts::STATUSES) as $key) { if ($key !== 'on_hold') { $words[$key] = RequestFacts::state(['label' => RequestFacts::STATUSES[$key][0]])->label(); } }
        return [
            Filter::select('status', 'Status', $words)->multiple()->query(fn (Builder $q, array $data): Builder => OperationQueries::status($q,
                in_array('under_review', $data['values'] ?? [], true) ? [...$data['values'], 'on_hold'] : ($data['values'] ?? []), $this->off())),
            Filter::select('action', 'Type', collect(Operation::query()->distinct()->pluck('action'))->mapWithKeys(fn ($a) => [$a => Labels::action($a)])->sort()->all())->multiple()
                ->query(fn (Builder $q, array $data): Builder => filled($data['values'] ?? []) ? $q->whereIn('action', $data['values']) : $q),
            Filter::select('server_id', 'Registrar account', \App\Models\Server::whereIn('id', Operation::query()->distinct()->pluck('server_id'))->pluck('name', 'id')->all())->multiple()
                ->query(fn (Builder $q, array $data): Builder => filled($data['values'] ?? []) ? $q->whereIn('server_id', $data['values']) : $q),
        ];
    }
    protected function defaultSort(): ?array { return ['id', 'desc']; }
    protected function searchLabel(): ?string { return 'Search domain'; }
    protected function emptyLine(): array { return ['No registrar requests', 'Requests show here when a customer orders a domain.', 'No requests match']; }
}
