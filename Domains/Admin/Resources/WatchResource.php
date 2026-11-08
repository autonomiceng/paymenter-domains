<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Tables\{Table,Columns\TextColumn,Filters\Filter,Filters\SelectFilter};
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\FontFamily;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DemoTag,EmptyTable,OneLine,Sheet,StaffDomainStatus,StatusMark,TableColumns,WatchReadResource,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
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
    /** Missing in danger with its cause after it; present values show $sub after them, on the same line. */
    private static function fact(TextColumn $column,\Closure $fact): TextColumn
    {
        $value=static function($record,$livewire)use($fact):array{
            $value=$fact($record,$livewire);
            // No expected check while monitoring is deliberately paused or stopped. Status says so once; these cells stay empty.
            if($record->state!=='active' && Missing::is($value['value']??null)){return ['value'=>'','tone'=>'gray'];}
            return $value;
        };
        return OneLine::apply($column->state(fn($record,$livewire)=>$value($record,$livewire)['value']??Missing::LABEL)
            ->color(fn($state,$record,$livewire)=>$state===Missing::LABEL?Missing::color($value($record,$livewire)['cause']??null):($value($record,$livewire)['tone']??null))->icon(fn($state,$record,$livewire)=>$state===Missing::LABEL?Missing::icon($value($record,$livewire)['cause']??null):null)
            ->description(fn($state,$record,$livewire)=>$state===Missing::LABEL?($value($record,$livewire)['cause']??null):($value($record,$livewire)['sub']??null)));
    }
    /** Last check and Expires, each without its sub-line: those go in Note. */
    private static function lastCheck($record,$livewire): array{return WatchFacts::lastCheck($record,$livewire->watchRow($record)['summary']);}
    private static function expires($record,$livewire): array
    { $row=$livewire->watchRow($record);return in_array('watch.no_coverage',$row['keys'],true)?['value'=>'']:WatchFacts::expires($row['summary'],$row['keys'],$record->state==='active',$record); }
    /** The status reason first, then what the last check and the expiry date add. Each says once. */
    private static function note($record,$livewire): ?string
    {
        $parts=array_values(array_unique(array_filter([$livewire->watchRow($record)['status']['reason'],self::lastCheck($record,$livewire)['sub']??null,self::expires($record,$livewire)['sub']??null])));
        return match(count($parts)){0=>null,1=>$parts[0],default=>implode(' ',array_map(StaffDomainStatus::sentence(...),$parts))};
    }
    /** Status reads at most CAP active names; the indicator says so only when more exist. */
    private static function capped(string $label): string{return WatchName::where('state','active')->count()>WatchFindings::CAP?$label.' (first 1,000 active domains)':$label;}
    public static function table(Table $table): Table
    {
        return TableColumns::choose(EmptyTable::apply($table,'No monitored domains','Turn on monitoring on a hosting product.','No monitored domains match'),[
            DemoTag::apply(OneLine::apply(TextColumn::make('canonical_name')->label('Domain')->searchable()->sortable()->fontFamily(FontFamily::Mono),OneLine::NAME)),
            OneLine::apply(TextColumn::make('customer')->label('Customer')->state(fn($record,$livewire)=>$livewire->watchRow($record)['customer']['label']),OneLine::SHORT),
            TextColumn::make('status')->label('Status')->badge()->state(fn($record,$livewire)=>$livewire->watchRow($record)['status']['label'])->color(fn($record,$livewire)=>$livewire->watchRow($record)['status']['tone'])->extraAttributes(fn($record,$livewire)=>StatusMark::attributes($livewire->watchRow($record)['status']['tone'],'watch-'.$record->id)),
            OneLine::apply(TextColumn::make('note')->label('Note')->color('gray')->state(fn($record,$livewire)=>self::note($record,$livewire))),
            self::fact(TextColumn::make('registration')->label('Registrar')->visibleFrom('xl'),fn($record)=>['value'=>WatchFindings::who($record->registration),'cause'=>WatchFacts::missingCause($record,'expiry')]),
            self::fact(TextColumn::make('delegation.dns')->label('Nameservers')->visibleFrom('lg'),fn($record)=>['value'=>WatchFindings::who($record->delegation?->dns),'cause'=>WatchFacts::missingCause($record,'delegation')]),
            self::fact(TextColumn::make('last_check')->label('Last check')->visibleFrom('md')->formatStateUsing(fn($state,$record,$livewire)=>($at=self::lastCheck($record,$livewire)['at']??0)?Sheet::moment($at):$state),
                fn($record,$livewire)=>[...self::lastCheck($record,$livewire),'sub'=>null]),
            self::fact(TextColumn::make('expiry')->label('Expires')->visibleFrom('lg'),fn($record,$livewire)=>[...self::expires($record,$livewire),'sub'=>null]),
        ],['registration','delegation.dns'])->filters([
            SelectFilter::make('status')->label('Status')->options(WatchFindings::STATUSES)->query(fn(Builder $query,array $data,$livewire)=>WatchFindings::filter($query,$data['value']??null,$livewire))
                ->indicateUsing(fn(array $data)=>isset(WatchFindings::STATUSES[$data['value']??''])?(in_array($data['value'],['monitoring','problem'],true)?self::capped('Status: '.WatchFindings::STATUSES[$data['value']]):'Status: '.WatchFindings::STATUSES[$data['value']]):null),
            Filter::make('customer')->label('Customer')->schema([TextInput::make('customer')->label('Customer')])->query(fn(Builder $query,array $data)=>WatchFindings::customer($query,$data['customer']??null))
                ->indicateUsing(fn(array $data)=>trim((string)($data['customer']??''))!==''?'Customer: '.trim((string)$data['customer']):null),
            SelectFilter::make('expiry')->label('Expires within')->options(['7'=>'7 days','30'=>'30 days','90'=>'90 days'])->query(fn(Builder $query,array $data,$livewire)=>$livewire->expiryQuery($query,(int)($data['value']??0)))
                ->indicateUsing(fn(array $data,$livewire)=>in_array($data['value']??null,['7','30','90'],true)?'Expires within '.$data['value'].' days'.($livewire->expiryScanCapped()?' (first 1,000 domains)':''):null),
        ])->recordUrl(fn($record)=>static::getUrl('view',['record'=>$record]))->defaultSort('canonical_name')->paginated([25,50,100])->defaultPaginationPageOption(25)->searchPlaceholder('Search domain');
    }
    public static function getPages(): array{return ['index'=>ListWatches::route('/'),'dns-failures'=>ListDnsFailures::route('/dns-failures'),'dns-setup'=>ListManagedDns::route('/dns-setup'),'settings'=>MonitoringSettings::route('/settings'),'view'=>ViewWatch::route('/{record}'),'history'=>WatchResource\Pages\WatchHistory::route('/{record}/history')];}
}
