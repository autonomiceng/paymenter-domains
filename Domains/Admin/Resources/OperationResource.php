<?php
// Adapted from Enom 444f31c OperationResource; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\{Builder,Model};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{NavigationCount,OperationQueries,Staff};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\{ListOperations,ViewOperation};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
class OperationResource extends Resource
{
    protected static ?string $model=Operation::class;
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-arrows-right-left';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?string $navigationLabel='Registrar requests';
    protected static bool $shouldRegisterNavigation=false;
    protected static ?string $modelLabel='registrar request';
    protected static ?string $pluralModelLabel='registrar requests';
    // Sentence case: Filament's default breadcrumb title-cases the plural label.
    protected static ?string $breadcrumb='Registrar requests';
    protected static ?string $slug='registrar-requests';
    public static function canViewAny(): bool { return Staff::can(); }
    public static function canView(Model $record): bool { return Staff::can(); }
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }
    public static function canGloballySearch(): bool { return false; }
    public static function getEloquentQuery(): Builder
    { Staff::requireView(); return OperationQueries::bound(parent::getEloquentQuery())->with('invoice:id,status'); }
    /** Statuses that wait on staff. Awaiting payment waits on the customer, and In progress on the registrar. */
    public const NEEDS_STAFF=['on_hold','under_review'];
    /** Menu count: held requests and those Under review, the list's Status filter for those two. Null at zero, so the menu shows nothing. */
    public static function getNavigationBadge(): ?string
    {
        if (!Staff::can()) { return null; }
        return NavigationCount::read('requests',fn()=>OperationQueries::status(static::getEloquentQuery(),self::NEEDS_STAFF)->count());
    }
    public static function getNavigationBadgeUrl(): string { return static::getUrl('index',['filters'=>['status'=>['values'=>self::NEEDS_STAFF]]]); }
    public static function getNavigationBadgeTooltip(): ?string { return 'Registrar requests, '.(static::getNavigationBadge() ?? '0').' need staff action'; }
    public static function getNavigationBadgeColor(): string { return 'warning'; }
    /** Demo requests keep the Demo tag; a row whose saved data can't be read still lists. */
    public static function demo(Operation $op): bool { try { return DemoData::is($op); } catch (\Throwable) { return false; } }
    public static function getPages(): array
    { return ['index'=>ListOperations::route('/'),'view'=>ViewOperation::route('/{record}')]; }
}
