<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Tables\{Table,Columns\TextColumn,Filters\Filter,Filters\SelectFilter};
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\FontFamily;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DemoTag,WatchReadResource,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainName,Missing};
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,MonitoringSettings,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProductMap};
class WatchResource extends WatchReadResource
{
    protected static ?string $model=WatchName::class;
    protected static ?string $navigationLabel='Domain monitoring';
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-eye';
    protected static ?int $navigationSort=6;
    protected static ?string $modelLabel='monitored domain';
    protected static ?string $pluralModelLabel='Domain monitoring';
    protected static ?string $breadcrumb='Domain monitoring';
    protected static ?string $slug='domain-watch';
    protected static ?SubNavigationPosition $subNavigationPosition=SubNavigationPosition::Top;
    /** Domains and Settings tabs on the list and Settings pages. */
    public static function tabs(string $active): array
    {
        return [NavigationItem::make('Domains')->url(static::getUrl('index'))->isActiveWhen(fn()=>$active==='index'),
            ...(MonitoringSettings::canAccess()?[NavigationItem::make('Settings')->url(static::getUrl('settings'))->isActiveWhen(fn()=>$active==='settings')]:[])];
    }
    public static function getEloquentQuery(): Builder{return parent::getEloquentQuery()->with(['delegation','registry','work']);}
    /** Missing in danger with its cause below; present values show $sub below. */
    private static function fact(TextColumn $column,\Closure $fact): TextColumn
    {
        $value=fn($record,$livewire)=>$fact($record,$livewire);
        return $column->state(fn($record,$livewire)=>$value($record,$livewire)['value']??Missing::LABEL)
            ->color(fn($state,$record,$livewire)=>$state===Missing::LABEL?Missing::color($value($record,$livewire)['cause']??null):($value($record,$livewire)['tone']??null))->icon(fn($state,$record,$livewire)=>$state===Missing::LABEL?Missing::icon($value($record,$livewire)['cause']??null):null)
            ->description(fn($state,$record,$livewire)=>$state===Missing::LABEL?($value($record,$livewire)['cause']??null):($value($record,$livewire)['sub']??null));
    }
    /** Status reads at most CAP active names; the indicator says so only when more exist. */
    private static function capped(string $label): string{return WatchName::where('state','active')->count()>WatchFindings::CAP?$label.' (first 1,000 active domains)':$label;}
    public static function table(Table $table): Table
    {
        $who=['platform'=>WatchFindings::company(),'external'=>'Other'];
        return $table->columns([
            DemoTag::apply(TextColumn::make('canonical_name')->label('Domain')->searchable()->sortable()->wrap()->fontFamily(FontFamily::Mono)->formatStateUsing(fn(string $state)=>DomainName::html($state))
                ->description(fn($record,$livewire)=>$livewire->watchRow($record)['customer']['label'])->extraAttributes(['style'=>DomainName::CELL])),
            TextColumn::make('status')->label('Status')->badge()->state(fn($record,$livewire)=>$livewire->watchRow($record)['status']['label'])->color(fn($record,$livewire)=>$livewire->watchRow($record)['status']['tone'])
                ->description(fn($record,$livewire)=>$livewire->watchRow($record)['status']['reason']),
            self::fact(TextColumn::make('registration')->label('Registrar')->visibleFrom('xl'),fn($record)=>['value'=>WatchFindings::who($record->registration),'cause'=>Missing::fills('after the next check')]),
            self::fact(TextColumn::make('delegation.dns')->label('Nameservers')->visibleFrom('lg'),fn($record)=>['value'=>WatchFindings::who($record->delegation?->dns),'cause'=>Missing::fills('after the next check')]),
            self::fact(TextColumn::make('last_check')->label('Last check')->visibleFrom('md'),fn($record,$livewire)=>WatchFacts::lastCheck($record,$livewire->watchRow($record)['summary'])),
            self::fact(TextColumn::make('expiry')->label('Expires')->visibleFrom('lg'),fn($record,$livewire)=>in_array('watch.no_coverage',$livewire->watchRow($record)['keys'],true)?['value'=>'']:WatchFacts::expires($livewire->watchRow($record)['summary'],$livewire->watchRow($record)['keys'],$record->state==='active')),
        ])->filters([
            SelectFilter::make('status')->label('Status')->options(WatchFindings::STATUSES)->query(fn(Builder $query,array $data,$livewire)=>WatchFindings::filter($query,$data['value']??null,$livewire))
                ->indicateUsing(fn(array $data)=>isset(WatchFindings::STATUSES[$data['value']??''])?(in_array($data['value'],['monitoring','problem'],true)?self::capped('Status: '.WatchFindings::STATUSES[$data['value']]):'Status: '.WatchFindings::STATUSES[$data['value']]):null),
            Filter::make('customer')->label('Customer')->schema([TextInput::make('customer')->label('Customer')])->query(fn(Builder $query,array $data)=>WatchFindings::customer($query,$data['customer']??null))
                ->indicateUsing(fn(array $data)=>trim((string)($data['customer']??''))!==''?'Customer: '.trim((string)$data['customer']):null),
            SelectFilter::make('registration')->label('Registrar')->options($who),
            SelectFilter::make('expected_dns')->label('Expected nameservers')->options($who),
            SelectFilter::make('observed')->label('Nameservers')->options($who)->query(fn(Builder $query,array $data)=>isset($data['value'])?$query->whereHas('delegation',fn($query)=>$query->where('dns',$data['value'])):$query),
            SelectFilter::make('product')->label('Hosting product')->options(fn()=>WatchProductMap::with('product')->get()->mapWithKeys(fn($m)=>[$m->product_id=>$m->product?->name??'Product #'.$m->product_id])->all())->query(fn(Builder $query,array $data)=>isset($data['value'])?$query->whereHas('sources',fn($query)=>$query->whereIn('map_id',WatchProductMap::where('product_id',$data['value'])->select('id'))):$query),
            SelectFilter::make('expiry')->label('Expires within')->options(['7'=>'7 days','30'=>'30 days','90'=>'90 days'])->query(fn(Builder $query,array $data,$livewire)=>$livewire->expiryQuery($query,(int)($data['value']??0)))
                ->indicateUsing(fn(array $data,$livewire)=>in_array($data['value']??null,['7','30','90'],true)?'Expires within '.$data['value'].' days'.($livewire->expiryScanCapped()?' (first 1,000 domains)':''):null),
        ])->recordUrl(fn($record)=>static::getUrl('view',['record'=>$record]))->defaultSort('canonical_name')->paginated([25,50])->defaultPaginationPageOption(25)->emptyStateHeading('No monitored domains')->emptyStateDescription('Turn on monitoring on a hosting product.');
    }
    public static function getPages(): array{return ['index'=>ListWatches::route('/'),'settings'=>MonitoringSettings::route('/settings'),'view'=>ViewWatch::route('/{record}')];}
}
