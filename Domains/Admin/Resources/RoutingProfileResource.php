<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Tables\{Table,Columns\TextColumn};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{EmptyTable,OneLine,OnOffSwitch,WatchReadResource};
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration,ViewConfiguration};
class RoutingProfileResource extends WatchReadResource
{
    protected static ?string $model=WatchProfile::class;
    protected static ?string $navigationLabel='DNS templates';
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-document-duplicate';
    protected static bool $shouldRegisterNavigation=false;
    protected static ?string $modelLabel='DNS template';
    protected static ?string $pluralModelLabel='DNS templates';
    protected static ?string $breadcrumb='DNS templates';
    protected static ?string $slug='dns-routing-profiles';
    public static function table(Table $table): Table
    {
        // One line per template: two nameservers, then how many more. The full list is on hover and on the template.
        $table=$table->columns([OneLine::apply(TextColumn::make('name')->label('Template')),TextColumn::make('nameservers')->label('Nameservers')->separator(', ')->limitList(2)
                ->tooltip(fn($record)=>count((array)$record->nameservers)>2?implode(', ',(array)$record->nameservers):null)->extraAttributes(['class'=>'domains-line']),
            TextColumn::make('enabled')->label('Status')->state(fn($record)=>$record->enabled?'On':'Off')->formatStateUsing(fn(string $state)=>OnOffSwitch::html($state==='On'))])
            ->recordUrl(fn($record)=>static::getUrl('view',['record'=>$record]))->paginated([25,50,100])->defaultPaginationPageOption(25);
        return EmptyTable::apply($table,'No DNS templates','Add one with Add DNS template.','No DNS templates match');
    }
    public static function getPages(): array{return ['index'=>ListConfiguration::route('/'),'view'=>ViewConfiguration::route('/{record}')];}
}
