<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Tables\{Table,Columns\TextColumn};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{WatchFacts,WatchReadResource};
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration,ViewConfiguration};
use Paymenter\Extensions\Servers\Domains\Services\WatchWork;
class RoutingProfileResource extends WatchReadResource
{
    protected static ?string $model=WatchProfile::class;
    protected static ?string $navigationLabel='DNS templates';
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-document-duplicate';
    protected static ?int $navigationSort=6;
    protected static ?string $modelLabel='DNS template';
    protected static ?string $pluralModelLabel='DNS templates';
    protected static ?string $breadcrumb='DNS templates';
    protected static ?string $slug='dns-routing-profiles';
    /** Configuration only helps after the deployment composes monitoring. */
    public static function shouldRegisterNavigation(): bool{return parent::shouldRegisterNavigation()&&app(WatchWork::class)->active();}
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder{return parent::getEloquentQuery()->withCount('productMaps');}
    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Template')->wrap(),TextColumn::make('nameservers')->label('Nameservers')->listWithLineBreaks()->wrap(),
            TextColumn::make('enabled')->label('Status')->state(fn($record)=>$record->enabled?'On':'Off'),
            TextColumn::make('used_by')->label('Used by')->state(fn($record)=>WatchFacts::products((int)$record->product_maps_count))])
            ->recordUrl(fn($record)=>static::getUrl('view',['record'=>$record]))->paginated([25,50])->defaultPaginationPageOption(25)->emptyStateHeading('No DNS templates');
    }
    public static function getPages(): array{return ['index'=>ListConfiguration::route('/'),'view'=>ViewConfiguration::route('/{record}')];}
}
