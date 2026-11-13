<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page;
use Filament\Resources\Pages\Page;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\{Builder, Model};
/** A resource's list of its saved records, such as monitored domains. It reads the resource's query, opens each row's
 * view page and checks the resource's list permission on every request, as Filament's own list page does.
 * See Concerns\RecordListType. */
abstract class RecordListPage extends Page implements PageType, HasTable
{
    use Concerns\RecordListType;
    protected const WIDE = true;
    protected const PAGE_CLASSES = ['domains-page-list', 'fi-resource-list-records-page'];
    public function mount(): void { $this->authorizeAccess(); }
    public function hydrate(): void { $this->authorizeAccess(); }
    protected function authorizeAccess(): void { abort_unless(static::getResource()::canViewAny(), 403); }
    protected function query(): Builder { return static::getResource()::getEloquentQuery(); }
    protected function rowUrl(Model $record): ?string { return static::getResource()::hasPage('view') ? static::getResource()::getUrl('view', ['record' => $record]) : null; }
}
