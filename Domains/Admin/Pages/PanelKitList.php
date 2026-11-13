<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Enums\SubNavigationPosition;
use Illuminate\Support\Collection;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Column, Filter, ListPage};
use Paymenter\Extensions\Servers\Domains\Presentation\{Reader, State};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** The kit's List example: a standard table of made-up monitored domains, supplied as columns, a filter, rows and a
 * bulk action. Demo systems only, like the kit. Its bulk action changes nothing. */
class PanelKitList extends ListPage
{
    protected static ?string $slug = 'panel-kit/list';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
    public static function canAccess(): bool { return PanelKit::canAccess(); }
    public function mount(): void { Staff::requireView(); abort_unless(DemoData::present(), 404); }
    public function getSubNavigation(): array { return PanelKit::tabs('List'); }
    protected function head(): Head { return Head::make('Monitored domains', Reader::Staff)->tag(true, false); }
    protected function columns(): array
    {
        return [
            Column::domain('domain', 'Domain')->searchable()->sortable(),
            Column::value('status', 'Status', static fn (array $row): Value => Value::state(State::from($row['status']), Reader::Staff)),
            Column::text('customer', 'Customer')->searchable(),
            Column::text('note', 'Note'),
        ];
    }
    protected function filters(): array
    { return [Filter::select('status', 'Status', array_combine(array_column(self::ROWS, 'status'), array_map(static fn (array $r): string => State::from($r['status'])->label(), self::ROWS)))]; }
    protected function rows(): array { return array_map(static fn (array $r): array => [...$r, 'key' => $r['domain'], 'url' => PanelKitDetail::getUrl()], self::ROWS); }
    protected function bulkActions(): array
    {
        return [BulkAction::make('kit_pause')->label('Pause monitoring')->action(static function (Collection $rows): void {
            Staff::requireView();
            Notification::make()->title('Paused '.implode(', ', $rows->pluck('domain')->all()))->success()->send();
        })];
    }
    protected function emptyLine(): array { return ['No monitored domains yet.', 'Add a domain to start checking it.']; }
    private const ROWS = [
        ['domain' => 'fieldnote.dev', 'status' => 'monitored', 'customer' => 'Ada Park', 'note' => 'Nameservers match the template.'],
        ['domain' => 'harbor-lights.example', 'status' => 'needs_review', 'customer' => 'Rui Costa', 'note' => 'Expires in 11 days.'],
        ['domain' => 'quietfield.example', 'status' => 'paused', 'customer' => 'Ada Park', 'note' => 'Paused by staff.'],
        ['domain' => 'saltmarsh.example', 'status' => 'failed', 'customer' => 'Mina Ito', 'note' => 'The last check failed.'],
    ];
}
