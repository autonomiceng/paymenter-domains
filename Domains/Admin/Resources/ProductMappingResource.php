<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\{Table,Columns\TextColumn};
use Paymenter\Extensions\Servers\Domains\Admin\Support\WatchReadResource;
use Paymenter\Extensions\Servers\Domains\Models\{WatchProductMap,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\ProductMappingResource\Pages\{ListConfiguration,ViewConfiguration};
class ProductMappingResource extends WatchReadResource
{
    protected static ?string $model=WatchProductMap::class;
    protected static ?string $navigationLabel='Hosting products';
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-cube';
    protected static ?int $navigationSort=7;
    protected static ?string $modelLabel='hosting product';
    protected static ?string $pluralModelLabel='Hosting products';
    protected static ?string $breadcrumb='Hosting products';
    protected static ?string $breadcrumb='Hosting products';
    protected static ?string $slug='product-domain-mappings';
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder{return parent::getEloquentQuery()->with('product');}
    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('product.name')->label('Product')->wrap(),TextColumn::make('property_key')->label('Domain field')->fontFamily(FontFamily::Mono),
            TextColumn::make('template')->label('DNS template')->state(fn($record)=>($record->expected_ns_profile_id?WatchProfile::whereKey($record->expected_ns_profile_id)->value('name'):null)??Missing::LABEL)->color(fn($state)=>$state===Missing::LABEL?Missing::COLOR:null)->icon(fn($state)=>$state===Missing::LABEL?Missing::ICON:null)
                ->description(fn($state)=>$state===Missing::LABEL?'Choose one in Edit':null),
            TextColumn::make('enabled')->label('Monitoring')->state(fn($record)=>$record->enabled?'On':'Off')])
            ->recordUrl(fn($record)=>static::getUrl('view',['record'=>$record]))->paginated([25,50])->defaultPaginationPageOption(25)->emptyStateHeading('No hosting products');
    }
    public static function getPages(): array{return ['index'=>ListConfiguration::route('/'),'view'=>ViewConfiguration::route('/{record}')];}
}
