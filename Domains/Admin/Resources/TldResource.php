<?php
// Adapted from Enom 444f31c Admin/Resources/TldResource; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Resources\Resource;
use Filament\Tables\{Columns\TextColumn,Filters\SelectFilter,Table};
use Illuminate\Database\Eloquent\{Builder,Model};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
class TldResource extends Resource
{
    protected static ?string $model=Tld::class;
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-tag';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?string $navigationLabel='TLDs & pricing';
    protected static ?string $modelLabel='TLD';
    protected static ?string $pluralModelLabel='TLDs';
    protected static ?string $slug='domain-tlds';
    public static function canViewAny(): bool {return Staff::can();}
    public static function canView(Model $record): bool {return Staff::can();}
    public static function canCreate(): bool {return false;}
    public static function canEdit(Model $record): bool {return false;}
    public static function canDelete(Model $record): bool {return false;}
    public static function canDeleteAny(): bool {return false;}
    public static function canGloballySearch(): bool {return false;}
    public static function getEloquentQuery(): Builder
    {Staff::requireView();return TldFacts::bound(parent::getEloquentQuery())->with('server:id,name');}
    public static function table(Table $table): Table
    {
        $grants=Staff::requireView();
        return $table->columns([
            TextColumn::make('tld')->label('TLD')->prefix('.')->searchable()->sortable()->extraAttributes(['style'=>'word-break:normal;overflow-wrap:normal'])
                ->description(fn($record)=>new \Illuminate\Support\HtmlString(e($record->use_default_rule?'Default rule':'TLD override').'<br>'.e(TldFacts::salesState($record,$grants)).'<br>Seller: '.e(TldFacts::seller($record))))->wrap(),
            TextColumn::make('registrar_key')->label('Registrar / account')->formatStateUsing(fn($state)=>TldFacts::registrar($state))
                ->description(fn($record)=>'Account #'.$record->server_id.' · '.$record->server->name),
            TextColumn::make('holders')->label('Renewal holders')->state(fn($record)=>TldFacts::holders($record)),
            TextColumn::make('renewal')->label('Annual renewal')->state(fn($record)=>collect(TldFacts::prices($record,$grants))->firstWhere('action','Renewal')['retail']??'Unknown')
                ->formatStateUsing(fn($state)=>new \Illuminate\Support\HtmlString('<span style="white-space:nowrap">'.e($state).'</span>'))
                ->description(function($record)use($grants){$row=collect(TldFacts::prices($record,$grants))->firstWhere('action','Renewal');return new \Illuminate\Support\HtmlString(
                    ($grants->costs?'Cost <span style="white-space:nowrap">'.e($row['cost']??'Unknown').'</span><br>':'').e(($row['warning']??'None')==='None'?'':$row['warning']));})->wrap(),
            TextColumn::make('refresh')->label('Cost refresh')->visibleFrom('xl')->state(fn($record)=>TldFacts::refreshState((int)$record->server_id))->wrap(),
        ])->filters([SelectFilter::make('registrar_key')->label('Registrar')->options(['enom'=>'Enom','namesilo'=>'NameSilo']),
            SelectFilter::make('server_id')->label('Account')->options(fn()=>\App\Models\Server::where('extension','Domains')->pluck('name','id')->all())])
            ->recordUrl(fn($record)=>self::getUrl('view',['record'=>$record]))->defaultSort('tld')->paginated([25,50,100])
            ->emptyStateHeading('No TLDs yet')->emptyStateDescription('Accepted catalogue imports appear here. New sales stay off until selected.');
    }
    public static function getPages(): array
    {return ['index'=>ListTlds::route('/'),'view'=>ViewTld::route('/{record}')];}
}
