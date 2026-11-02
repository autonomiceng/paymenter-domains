<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Tables\{Table,Columns\TextColumn,Filters\Filter,Filters\SelectFilter};
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\FontFamily;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DemoTag,OneLine,StatusMark,WatchReadResource,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainName,Missing};
use Filament\Pages\Enums\SubNavigationPosition;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ListManagedDns,ListDnsFailures,MonitoringSettings,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
class WatchResource extends WatchReadResource
{
    protected static ?string $model=WatchName::class;
    protected static ?string $navigationLabel='Domain monitoring';
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-eye';
    protected static ?int $navigationSort=4;
    protected static ?string $modelLabel='monitored domain';
    protected static ?string $pluralModelLabel='Domain monitoring';
    protected static ?string $breadcrumb='Domain monitoring';
    protected static ?string $slug='domain-watch';
    protected static ?SubNavigationPosition $subNavigationPosition=SubNavigationPosition::Top;
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
        return $table->columns([
            DemoTag::apply(OneLine::apply(TextColumn::make('canonical_name')->label('Domain')->searchable()->sortable()->fontFamily(FontFamily::Mono)->formatStateUsing(fn(string $state)=>DomainName::html($state)),OneLine::NAME)),
            OneLine::apply(TextColumn::make('customer')->label('Customer')->state(fn($record,$livewire)=>$livewire->watchRow($record)['customer']['label']),OneLine::SHORT),
            TextColumn::make('status')->label('Status')->badge()->state(fn($record,$livewire)=>$livewire->watchRow($record)['status']['label'])->color(fn($record,$livewire)=>$livewire->watchRow($record)['status']['tone'])->extraAttributes(fn($record,$livewire)=>StatusMark::attributes($livewire->watchRow($record)['status']['tone'],'watch-'.$record->id)),
            OneLine::apply(TextColumn::make('note')->label('Note')->color('gray')->state(fn($record,$livewire)=>$livewire->watchRow($record)['status']['reason'])),
            self::fact(TextColumn::make('registration')->label('Registrar')->visibleFrom('xl'),fn($record)=>['value'=>WatchFindings::who($record->registration),'cause'=>Missing::fills('after the next check')]),
            self::fact(TextColumn::make('delegation.dns')->label('Nameservers')->visibleFrom('lg'),fn($record)=>['value'=>WatchFindings::who($record->delegation?->dns),'cause'=>Missing::fills('after the next check')]),
            self::fact(TextColumn::make('last_check')->label('Last check')->visibleFrom('md'),fn($record,$livewire)=>WatchFacts::lastCheck($record,$livewire->watchRow($record)['summary'])),
            self::fact(TextColumn::make('expiry')->label('Expires')->visibleFrom('lg'),fn($record,$livewire)=>in_array('watch.no_coverage',$livewire->watchRow($record)['keys'],true)?['value'=>'']:WatchFacts::expires($livewire->watchRow($record)['summary'],$livewire->watchRow($record)['keys'],$record->state==='active')),
        ])->filters([
            SelectFilter::make('status')->label('Status')->options(WatchFindings::STATUSES)->query(fn(Builder $query,array $data,$livewire)=>WatchFindings::filter($query,$data['value']??null,$livewire))
                ->indicateUsing(fn(array $data)=>isset(WatchFindings::STATUSES[$data['value']??''])?(in_array($data['value'],['monitoring','problem'],true)?self::capped('Status: '.WatchFindings::STATUSES[$data['value']]):'Status: '.WatchFindings::STATUSES[$data['value']]):null),
            Filter::make('customer')->label('Customer')->schema([TextInput::make('customer')->label('Customer')])->query(fn(Builder $query,array $data)=>WatchFindings::customer($query,$data['customer']??null))
                ->indicateUsing(fn(array $data)=>trim((string)($data['customer']??''))!==''?'Customer: '.trim((string)$data['customer']):null),
            SelectFilter::make('expiry')->label('Expires within')->options(['7'=>'7 days','30'=>'30 days','90'=>'90 days'])->query(fn(Builder $query,array $data,$livewire)=>$livewire->expiryQuery($query,(int)($data['value']??0)))
                ->indicateUsing(fn(array $data,$livewire)=>in_array($data['value']??null,['7','30','90'],true)?'Expires within '.$data['value'].' days'.($livewire->expiryScanCapped()?' (first 1,000 domains)':''):null),
        ])->recordUrl(fn($record)=>static::getUrl('view',['record'=>$record]))->defaultSort('canonical_name')->paginated([25,50,100])->defaultPaginationPageOption(25)->searchPlaceholder('Search domain')->emptyStateHeading('No monitored domains')->emptyStateDescription('Turn on monitoring on a hosting product.');
    }
    public static function getPages(): array{return ['index'=>ListWatches::route('/'),'dns-failures'=>ListDnsFailures::route('/dns-failures'),'dns-setup'=>ListManagedDns::route('/dns-setup'),'settings'=>MonitoringSettings::route('/settings'),'view'=>ViewWatch::route('/{record}'),'history'=>WatchResource\Pages\WatchHistory::route('/{record}/history')];}
}
