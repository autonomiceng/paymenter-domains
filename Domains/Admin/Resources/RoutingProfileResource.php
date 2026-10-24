<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Tables\{Table,Columns\TextColumn};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{WatchFacts,WatchReadResource};
use Paymenter\Extensions\Servers\Domains\Models\{WatchProductMap,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration,ViewConfiguration};
class RoutingProfileResource extends WatchReadResource
{
    protected static ?string $model=WatchProfile::class;
    protected static ?string $navigationLabel='DNS templates';
    protected static ?int $navigationSort=6;
    protected static ?string $modelLabel='DNS template';
    protected static ?string $pluralModelLabel='DNS templates';
    protected static ?string $breadcrumb='DNS templates';
    protected static ?string $slug='dns-routing-profiles';
    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Template')->wrap(),TextColumn::make('nameservers')->label('Nameservers')->listWithLineBreaks()->wrap(),
            TextColumn::make('enabled')->label('Status')->state(fn($record)=>$record->enabled?'On':'Off'),
            TextColumn::make('used_by')->label('Used by')->state(fn($record)=>WatchFacts::products(WatchProductMap::where('expected_ns_profile_id',$record->id)->count()))])
            ->recordUrl(fn($record)=>static::getUrl('view',['record'=>$record]))->paginated([25,50])->defaultPaginationPageOption(25)->emptyStateHeading('No DNS templates');
    }
    public static function getPages(): array{return ['index'=>ListConfiguration::route('/'),'view'=>ViewConfiguration::route('/{record}')];}
}
