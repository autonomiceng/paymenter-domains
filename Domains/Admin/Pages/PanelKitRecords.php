<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use App\Models\Server;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Enums\SubNavigationPosition;
use Illuminate\Database\Eloquent\{Builder, Collection};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Column, Filter, QueryListPage};
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Presentation\{Plain, Reader};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** The kit's saved-record List example: the registrar accounts as an Eloquent list, with a column and a filter that
 * pass their own query, and a bulk action. Demo systems only, like the kit. Its bulk action changes nothing. */
class PanelKitRecords extends QueryListPage
{
    protected static ?string $slug = 'panel-kit/records';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
    public static function canAccess(): bool { return PanelKit::canAccess(); }
    public function mount(): void { Staff::requireView(); abort_unless(DemoData::present(), 404); }
    public function getSubNavigation(): array { return PanelKit::tabs('Record list'); }
    protected function head(): Head { return Head::make('Registrar accounts', Reader::Staff)->tag(true, false); }
    protected function query(): Builder { return Account::query(); }
    protected function columns(): array
    {
        return [
            Column::text('server_id', 'Account', static fn (Account $a): string => (Server::find($a->server_id)?->name ?? 'Account').' #'.$a->server_id)
                ->searchable(static fn (Builder $q, string $search): Builder => $q->whereIn('server_id', Server::where('name', 'like', '%'.$search.'%')->pluck('id')))->sortable(),
            Column::text('registrar_key', 'Registrar', static fn (Account $a): string => ['enom' => 'Enom', 'namesilo' => 'NameSilo'][$a->registrar_key] ?? $a->registrar_key)->sortable(),
            Column::value('environment', 'Mode', static fn (Account $a): Value => Value::plain($a->environment === Environment::Live->value ? Plain::Live : Plain::Test)),
            Column::value('processing_enabled', 'Orders', static fn (Account $a): Value => Value::plain($a->processing_enabled ? Plain::On : Plain::Off)),
        ];
    }
    protected function filters(): array
    {
        return [
            Filter::select('registrar_key', 'Registrar', ['enom' => 'Enom', 'namesilo' => 'NameSilo'])
                ->query(static fn (Builder $q, array $data): Builder => filled($data['value'] ?? null) ? $q->where('registrar_key', $data['value']) : $q),
            Filter::toggle('live', 'Live accounts only', static fn (Builder $q): Builder => $q->where('environment', Environment::Live->value)),
        ];
    }
    protected function defaultSort(): ?array { return ['server_id', 'asc']; }
    protected function searchLabel(): ?string { return 'Search account'; }
    protected function emptyLine(): array { return ['No registrar accounts yet.', 'Connect an account in Registrars.']; }
    protected function bulkActions(): array
    {
        return [BulkAction::make('kit_check')->label('Check balances')->action(static function (Collection $records): void {
            Staff::requireView();
            Notification::make()->title('Checked '.$records->count().' '.($records->count() === 1 ? 'account' : 'accounts'))->success()->send();
        })];
    }
}
