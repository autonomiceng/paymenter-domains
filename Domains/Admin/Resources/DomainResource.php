<?php
// Adapted from Enom 444f31c DomainResource; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Carbon\CarbonImmutable;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use App\Models\ServiceCancellation;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Filters\{Filter, SelectFilter};
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DemoTag, DomainFacts, DomainQueries, Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{AdoptDomain, AdoptionPreview, ListDomains, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Adoption, Domain};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainStatus, Missing};
use Paymenter\Extensions\Servers\Domains\Services\{NativeCancellation, ObservationWork, RenewalStop};
class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';
    protected static string|\UnitEnum|null $navigationGroup = 'Domains';
    protected static ?string $navigationLabel = 'Domains';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'domain';
    protected static ?string $pluralModelLabel = 'domains';
    protected static ?string $breadcrumb = 'Domains';
    protected static ?string $slug = 'domains';
    protected static ?string $recordTitleAttribute = 'domain';
    public static function canViewAny(): bool { return Staff::can(); }
    public static function canView(Model $record): bool { return Staff::can(); }
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }
    public static function canGloballySearch(): bool { return false; }
    public static function getEloquentQuery(): Builder
    {
        Staff::requireView();
        return parent::getEloquentQuery();
    }
    public static function table(Table $table): Table
    {
        $late=DB::table('domains_obligation_alerts as late')->selectRaw('count(*)')->whereColumn('late.domain_id','domains_domains.id')
            ->where('late.code','acquisition_late_money')->whereNull('late.resolved_at');
        return $table->modifyQueryUsing(fn (Builder $query) => $query->select('domains_domains.*')->selectSub($late,'late_money')
            ->with(['user:id,first_name,last_name,email', 'server:id,name', 'latestAcquisition:domains_operations.id,domains_operations.domain_id,domains_operations.action,domains_operations.state,domains_operations.safe_code,domains_operations.service_id,domains_operations.server_id,domains_operations.registrar_key,domains_operations.authority_generation,domains_operations.account_fingerprint']))->columns([
            DemoTag::apply(TextColumn::make('domain')->label('Domain')->searchable()->sortable()->wrap()->fontFamily(FontFamily::Mono)
                ->description(fn(Domain $record)=>$record->user?(trim($record->user->first_name.' '.$record->user->last_name)?:$record->user->email):null)
                ->extraAttributes(['style'=>'overflow-wrap:anywhere;max-width:24rem'])),
            TextColumn::make('status')->label('Status')->badge()->state(fn(Domain $record,$livewire)=>self::status($record,$livewire)['label'])
                ->color(fn(Domain $record,$livewire)=>self::status($record,$livewire)['tone'])->description(fn(Domain $record,$livewire)=>self::status($record,$livewire)['reason']
                    ??match(self::row($record,$livewire)['sync']){'failed'=>'Sync failed','paused'=>'Sync paused',default=>null}),
            TextColumn::make('provider_expires_at')->label('Expires')->state(fn(Domain $record)=>$record->provider_expires_at?->format('M j, Y')
                ?? (self::registered($record)?Missing::LABEL:null))->color(fn($state)=>$state===Missing::LABEL?Missing::COLOR:null)->icon(fn($state)=>$state===Missing::LABEL?Missing::ICON:null)
                ->description(fn(Domain $record,$state,$livewire)=>$state===Missing::LABEL?DomainFacts::unread($record,self::row($record,$livewire)['sync']):null)
                ->sortable(query: fn(Builder $query,string $direction)=>$query->orderBy('provider_expires_at',$direction)),
            // Auto-renew applies in every state, so pending and under-review rows show it too.
            TextColumn::make('auto_renew')->label('Auto-renew')->state(fn(Domain $record,$livewire)=>match(self::row($record,$livewire)['off']) {
                null=>Missing::LABEL,true=>'Off',false=>'On'})
                ->color(fn($state)=>$state===Missing::LABEL?Missing::COLOR:null)->icon(fn($state)=>$state===Missing::LABEL?Missing::ICON:null)
                ->description(fn($state)=>$state===Missing::LABEL?DomainFacts::UNREADABLE_RENEWAL:null),
            TextColumn::make('server_id')->label('Registrar')->state(fn(Domain $record)=>$record->server?->name??Missing::LABEL)
                ->color(fn($state)=>$state===Missing::LABEL?Missing::COLOR:null)->icon(fn($state)=>$state===Missing::LABEL?Missing::ICON:null)
                ->description(fn(Domain $record,$state)=>$state===Missing::LABEL?'Registrar account not found':(self::REGISTRARS[$record->registrar_key]??null)),
        ])->filters([
            SelectFilter::make('status')->label('Status')->multiple()->options(self::statusOptions())
                ->query(fn(Builder $query,array $data)=>DomainQueries::status($query,$data['values']??[])),
            SelectFilter::make('server_id')->label('Registrar account')->options(function(): array {
                if (!Staff::can()) { return []; }
                return \App\Models\Server::whereIn('id',Account::query()->pluck('server_id'))->orderBy('name')->pluck('name','id')->all();
            }),
            SelectFilter::make('expiring')->label('Expires within')->options(['7'=>'7 days','30'=>'30 days','60'=>'60 days','90'=>'90 days'])
                ->query(function(Builder $query,array $data) {
                    $days=(int)($data['value']??0);
                    return in_array($days,[7,30,60,90],true) ? $query->whereDate('provider_expires_at','>=',CarbonImmutable::today())
                        ->whereDate('provider_expires_at','<=',CarbonImmutable::today()->addDays($days)) : $query;
                }),
            // The account page's Domain alerts row links here with this filter and the account.
            Filter::make('alerts')->label('Open alerts')->toggle()
                ->query(fn(Builder $query)=>$query->whereExists(fn($q)=>$q->selectRaw('1')->from('domains_obligation_alerts as a')
                    ->whereColumn('a.domain_id','domains_domains.id')->whereNull('a.resolved_at'))),
        ])->recordUrl(fn(Domain $record)=>static::getUrl('view',['record'=>$record]))
            ->defaultSort('domain')->paginated([25,50,100])->defaultPaginationPageOption(25)
            ->emptyStateHeading('No domains yet')->emptyStateDescription(null);
    }
    /** The nine domain statuses, in DomainStatus order. */
    public static function statusOptions(): array { return array_map(fn(array $s)=>$s[0],DomainStatus::SET); }
    private const REGISTRARS=['enom'=>'Enom','namesilo'=>'NameSilo'];
    /** Auto-renew stops and sync state per rendered page of records, keyed by domain id: one batch of reads per page. */
    private static ?\WeakMap $rows=null;
    private static function status(Domain $record, mixed $livewire): array
    {
        return DomainFacts::status($record,(bool)self::row($record,$livewire)['off'],(int)($record->late_money??0)>0);
    }
    private static function registered(Domain $record): bool
    { return $record->adoptionPending()||in_array($record->status,['active','expired','grace','redemption'],true); }
    /** 'off': true when automatic renewal is off, null when its saved data cannot be read. 'sync': DomainFacts::sync(). */
    private static function row(Domain $record, mixed $livewire): array
    {
        $records=method_exists($livewire,'getTableRecords')?$livewire->getTableRecords():collect([$record]);
        self::$rows??=new \WeakMap();
        $cached=self::$rows[$records]??[];
        if (!array_key_exists($record->id,$cached)) {
            $page=collect(method_exists($records,'items')?$records->items():$records->all())->filter(fn($r)=>$r instanceof Domain);
            if (!$page->contains('id',$record->id)) { $page=collect([$record]); }
            $services=$page->pluck('service_id')->filter()->unique()->values()->all();
            $cancel=NativeCancellation::withIntents(ServiceCancellation::whereIn('service_id',$services)->get(),$services,true);
            $ids=$page->pluck('adoption_id')->filter()->unique()->all();
            $adoptions=$ids?Adoption::whereIn('id',$ids)->get()->keyBy('id'):collect();
            $accounts=Account::whereIn('server_id',$page->pluck('server_id')->unique()->values()->all())->get();
            $stopped=ObservationWork::stoppedIds($page);
            foreach ($page as $d) {
                try { $off=RenewalStop::stopped(['domain'=>$d,'adoption'=>$adoptions[$d->adoption_id]??null,
                    'cancel'=>$cancel->where('service_id',$d->service_id)->values()]); }
                catch (\Throwable) { $off=null; }
                $account=$accounts->first(fn(Account $a)=>(int)$a->server_id===(int)$d->server_id&&$a->registrar_key===$d->registrar_key
                    &&$a->account_fingerprint===$d->account_fingerprint);
                $cached[$d->id]=['off'=>$off,'sync'=>DomainFacts::sync($d,$account,isset($stopped[$d->id]))];
            }
            self::$rows[$records]=$cached;
        }
        return $cached[$record->id];
    }
    public static function getRelations(): array { return [RequestsRelationManager::class]; }
    public static function getPages(): array { return ['index'=>ListDomains::route('/'),'adopt'=>AdoptDomain::route('/adopt'),'view'=>ViewDomain::route('/{record}'),'adoption'=>AdoptionPreview::route('/{record}/adoption')]; }
}
