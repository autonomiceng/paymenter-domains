<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages;
use Illuminate\Database\Eloquent\{Builder, Model};
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource, RoutingProfileResource, WatchResource};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Head;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Column, RecordListPage};
use Paymenter\Extensions\Servers\Domains\Admin\Support\WatchFacts;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName, WatchProfile};
use Paymenter\Extensions\Servers\Domains\Presentation\Reader;

class AssignedDomains extends RecordListPage
{
    protected static string $resource = RoutingProfileResource::class;
    #[Locked] public WatchProfile $profile;
    public function mount(int|string|null $record = null): void
    {
        parent::mount();
        $this->profile = WatchProfile::findOrFail($record);
    }
    protected function head(): Head { return Head::make('Monitored domains', Reader::Staff)->link($this->profile->name, RoutingProfileResource::getUrl('view', ['record' => $this->profile])); }
    protected function query(): Builder
    {
        return WatchName::query()->fromSub(WatchFacts::assignments($this->profile->id), 'domains_watch_names');
    }
    protected function columns(): array
    {
        return [Column::domain('domain', 'Domain', false)->whole()->searchable(), Column::text('inherited', 'Assignment',
            fn (Model $record) => $record->inherited ? 'Site default' : 'Selected template')];
    }
    protected function rowUrl(Model $record): ?string
    {
        if ($record->kind === 'watch') { return WatchResource::getUrl('view', ['record' => $record->id]); }
        return DomainResource::canViewAny() ? DomainResource::getUrl('view', ['record' => abs($record->id)]) : null;
    }
    protected function searchLabel(): ?string { return 'Search domains'; }
    protected function emptyLine(): array { return ['No domains assigned', 'Domains appear here when they use this template.']; }
}
