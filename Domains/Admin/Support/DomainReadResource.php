<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\{Builder,Model};
/** Read-only resource boundary; configuration writes use a separate reviewed action. */
abstract class DomainReadResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-eye';
    public static function canViewAny(): bool{return false;}
    public static function canView(Model $record): bool{return static::canViewAny();}
    public static function canCreate(): bool{return false;}
    public static function canEdit(Model $record): bool{return false;}
    public static function canDelete(Model $record): bool{return false;}
    public static function canDeleteAny(): bool{return false;}
    public static function canGloballySearch(): bool{return false;}
    public static function getEloquentQuery(): Builder{abort_unless(static::canViewAny(),403);return parent::getEloquentQuery();}
}
