<?php
// Adapted from Enom 444f31c Admin/Resources/TldResource; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\{Builder,Model};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
class TldResource extends Resource
{
    protected static ?string $model=Tld::class;
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-tag';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?string $navigationLabel='TLDs & pricing';
    protected static ?int $navigationSort=3;
    /** The menu item stays marked on What you sell, its tab. */
    public static function getNavigationItemActiveRoutePattern(): string|array { return [static::getRouteBaseName().'.*',\Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell::getRouteName()]; }
    protected static ?string $modelLabel='TLD';
    protected static ?string $pluralModelLabel='TLDs';
    protected static ?string $breadcrumb='TLDs & pricing';
    protected static ?string $slug='tlds';
    public static function canViewAny(): bool {return Staff::can();}
    public static function canView(Model $record): bool {return Staff::can();}
    public static function canCreate(): bool {return false;}
    public static function canEdit(Model $record): bool {return false;}
    public static function canDelete(Model $record): bool {return false;}
    public static function canDeleteAny(): bool {return false;}
    public static function canGloballySearch(): bool {return false;}
    public static function getEloquentQuery(): Builder
    {Staff::requireView();return TldFacts::bound(parent::getEloquentQuery())->with('server:id,name');}
    /** Accounts whose last price sync failed, with the stored cause in words. */
    public static function syncFailures(): array
    {
        $out=[];
        foreach(Account::query()->whereIn('server_id',\App\Models\Server::where('extension','Domains')->where('type','server')->select('id'))->orderBy('server_id')->get() as $a){
            if($failure=TldFacts::syncFailure((int)$a->server_id)){$out[]=['account'=>(string)\App\Models\Server::whereKey($a->server_id)->value('name').' (account '.$a->server_id.')',...$failure];}
        }
        return $out;
    }
    public static function getPages(): array
    {return ['index'=>ListTlds::route('/'),'view'=>ViewTld::route('/{record}'),'history'=>TldResource\Pages\TldHistory::route('/{record}/history')];}
}
