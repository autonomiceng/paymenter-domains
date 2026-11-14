<?php
// Adapted from Enom 444f31c DomainResource; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\{Builder, Model};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainQueries, NavigationCount, Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{AdoptDomain, AdoptionPreview, ListDomains, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus;
class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';
    protected static string|\UnitEnum|null $navigationGroup = 'Domains';
    protected static ?string $navigationLabel = 'Domains';
    protected static ?int $navigationSort = 1;
    /** The menu item stays marked on every tab of the Domains area (StaffTabs::domains) and the pages under them. */
    public static function getNavigationItemActiveRoutePattern(): string|array { return [static::getRouteBaseName().'.*',RoutingProfileResource::getRouteBaseName().'.*',WatchResource::getRouteBaseName().'.*']; }
    protected static ?string $modelLabel = 'domain';
    protected static ?string $pluralModelLabel = 'domains';
    protected static ?string $breadcrumb = 'Domains';
    protected static ?string $slug = 'domains';
    protected static ?string $recordTitleAttribute = 'domain';
    public static function canViewAny(): bool { return Staff::can(); }
    public static function canView(Model $record): bool { return Staff::can(); }
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }
    public static function canGloballySearch(): bool { return false; }
    public static function getEloquentQuery(): Builder
    {
        Staff::requireView();
        return parent::getEloquentQuery();
    }
    /** Menu count: domains with an open alert, the list's Open alerts filter. Null at zero, so the menu shows nothing. */
    public static function getNavigationBadge(): ?string
    {
        if (!Staff::can()) { return null; }
        return NavigationCount::read('domains',fn()=>DomainQueries::openAlertCount(Domain::query()));
    }
    public static function getNavigationBadgeUrl(): string { return static::getUrl('index',['filters'=>['alerts'=>['isActive'=>true]]]); }
    public static function getNavigationBadgeTooltip(): ?string { return 'Domains, '.(static::getNavigationBadge() ?? '0').' need staff action'; }
    public static function getNavigationBadgeColor(): string { return 'warning'; }
    /** The domain statuses, in DomainStatus order. */
    public static function statusOptions(): array { return array_map(fn(array $s)=>$s[0],DomainStatus::SET); }
    public static function getRelations(): array { return [RequestsRelationManager::class]; }
    public static function getPages(): array { return ['index'=>ListDomains::route('/'),'adopt'=>AdoptDomain::route('/import'),'view'=>ViewDomain::route('/{record}'),'adoption'=>AdoptionPreview::route('/{record}/import-preview')]; }
}
