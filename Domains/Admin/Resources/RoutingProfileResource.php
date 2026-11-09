<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\{Table,Columns\TextColumn};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{EmptyTable,OneLine,StatusMark,WatchReadResource};
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
        // Long text: nameservers wrap to 2 lines (table standard). The full list is on hover and on the template.
        // The detail page has the monitoring switch. Only the single site choice supplies new registrations.
        // Saved references that cannot be used are marked too, matching the guard that keeps them.
        $marks=null;
        $mark=static function(int $id)use(&$marks):?string {$marks??=\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::marks();return $marks[$id]??null;};
        $table=$table->columns([OneLine::apply(TextColumn::make('name')->label('Template')->description(fn($record)=>$mark((int)$record->id))),TextColumn::make('nameservers')->label('Nameservers')->separator(', ')->wrap()->lineClamp(2)->fontFamily(FontFamily::Mono)
                ->tooltip(fn($record)=>implode(', ',(array)$record->nameservers)?:null),
            TextColumn::make('enabled')->label('Status')->badge()->state(fn($record)=>self::status($record)['label'])->color(fn($record)=>self::status($record)['tone'])
                ->extraAttributes(fn($record)=>StatusMark::attributes(self::status($record)['tone'],'template-'.$record->id))])
            ->recordUrl(fn($record)=>static::getUrl('view',['record'=>$record]))->paginated([25,50,100])->defaultPaginationPageOption(25);
        return EmptyTable::apply($table,'No DNS templates','Add one with Add DNS template.','No DNS templates match');
    }
    /** On or Off, with its mark tone. Off is gray: a template is off until staff turn it on. @return array{label:string,tone:string} */
    public static function status(WatchProfile $record): array{return $record->enabled?['label'=>'On','tone'=>'success']:['label'=>'Off','tone'=>'gray'];}
    public static function getPages(): array{return ['index'=>ListConfiguration::route('/'),'view'=>ViewConfiguration::route('/{record}')];}
}
