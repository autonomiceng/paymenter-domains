<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Tables\{Table,Columns\TextColumn};
use Paymenter\Extensions\Servers\Domains\Admin\Support\WatchReadResource;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration,ViewConfiguration};
class RoutingProfileResource extends WatchReadResource
{
    protected static ?string $model=WatchProfile::class;
    protected static ?string $navigationLabel='DNS and routing profiles';
    protected static ?string $pluralModelLabel='DNS and routing profiles';
    protected static ?string $slug='dns-routing-profiles';
    public static function table(Table $table): Table
    {return $table->columns([TextColumn::make('name')->label('Profile')->wrap(),TextColumn::make('nameservers')->label('Nameservers')->listWithLineBreaks()->wrap(),TextColumn::make('enabled')->label('Discovery / profile')->formatStateUsing(fn($state)=>$state?'Enabled':'Disabled')->badge(),TextColumn::make('revision')->label('Revision')])->recordUrl(fn($record)=>static::getUrl('view',['record'=>$record]))->paginated([25,50])->defaultPaginationPageOption(25);}
    public static function getPages(): array{return ['index'=>ListConfiguration::route('/'),'view'=>ViewConfiguration::route('/{record}')];}
}
