<?php
// Adapted from Enom 444f31c DomainResource; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Carbon\CarbonImmutable;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Filters\{Filter, SelectFilter};
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\{Builder, Model};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DemoTag, DomainFacts, DomainQueries, EmptyTable, NavigationCount,OneLine, Staff, StaffDomainInputs, StatusMark};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{AdoptDomain, AdoptionPreview, ListDomains, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainStatus, Missing};
use Paymenter\Extensions\Servers\Domains\Services\ObservationWork;
class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';
    protected static string|\UnitEnum|null $navigationGroup = 'Domains';
    protected static ?string $navigationLabel = 'Domains';
    protected static ?int $navigationSort = 1;
    /** The menu item stays marked on DNS templates, its tab. */
    public static function getNavigationItemActiveRoutePattern(): string|array { return [static::getRouteBaseName().'.*',RoutingProfileResource::getRouteBaseName().'.*']; }
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
    /** Menu count: domains with an open alert, the list's Open alerts filter. Null at zero, so the menu shows nothing. */
    public static function getNavigationBadge(): ?string
    {
        if (!Staff::can()) { return null; }
        return NavigationCount::read('domains',fn()=>DomainQueries::openAlertCount(Domain::query()));
    }
    public static function getNavigationBadgeColor(): string { return 'warning'; }
    public static function table(Table $table): Table
    {
        EmptyTable::apply($table,'No domains yet','They show here after a customer orders one.','No domains match');
        return $table->modifyQueryUsing(fn (Builder $query) => $query
            ->with(['user:id,first_name,last_name,email', 'server:id,name', 'latestAcquisition']))->columns([
            DemoTag::apply(OneLine::apply(TextColumn::make('domain')->label('Domain')->searchable()->sortable()->fontFamily(FontFamily::Mono),OneLine::NAME)),
            OneLine::apply(TextColumn::make('customer')->label('Customer')->state(fn(Domain $record)=>$record->user?(trim($record->user->first_name.' '.$record->user->last_name)?:$record->user->email):null)
                ->searchable(query:fn(Builder $query,string $search)=>self::customerSearch($query,$search)),OneLine::SHORT),
            TextColumn::make('status')->label('Status')->badge()->state(fn(Domain $record,$livewire)=>self::status($record,$livewire)['label'])
                ->extraAttributes(fn(Domain $record,$livewire)=>StatusMark::attributes(self::tone($record,$livewire),'domain-'.$record->id))
                ->color(fn(Domain $record,$livewire)=>self::tone($record,$livewire)),
            // The step comes first, so a cut note still shows it. Every open alert is listed on the domain page. A step that names a request opens it.
            OneLine::apply(TextColumn::make('note')->label('Note')->state(fn(Domain $record,$livewire)=>self::note($record,$livewire)['text'])->color('gray')
                ->formatStateUsing(fn(string $state,Domain $record,$livewire)=>DomainFacts::noteStep($state,self::status($record,$livewire)))
                ->url(fn(Domain $record,$livewire)=>DomainFacts::requestUrl(self::status($record,$livewire)))
                ->extraAttributes(fn(Domain $record,$livewire)=>StatusMark::note(self::note($record,$livewire)['act']))),
            OneLine::apply(TextColumn::make('provider_expires_at')->label('Expires')->state(fn(Domain $record)=>$record->provider_expires_at?->format('M j, Y')
                ?? (self::registered($record)?Missing::LABEL:null))->color(fn($state,Domain $record,$livewire)=>$state===Missing::LABEL?Missing::color(DomainFacts::unread($record,self::row($record,$livewire)['sync'])):null)
                ->icon(fn($state,Domain $record,$livewire)=>$state===Missing::LABEL?Missing::icon(DomainFacts::unread($record,self::row($record,$livewire)['sync'])):null)
                ->description(fn(Domain $record,$state,$livewire)=>$state===Missing::LABEL?DomainFacts::unread($record,self::row($record,$livewire)['sync']):null)
                ->sortable(query: fn(Builder $query,string $direction)=>$query->orderBy('provider_expires_at',$direction))),
            // Renewal invoices apply until the domain has ended, so pending and under-review rows show it too.
            OneLine::apply(TextColumn::make('auto_renew')->label('Renewal invoices')->state(fn(Domain $record,$livewire)=>DomainFacts::ended($record)?null:match(self::row($record,$livewire)['off']) {
                null=>Missing::LABEL,true=>'Off',false=>'On'})
                ->color(fn($state)=>$state===Missing::LABEL?Missing::COLOR:null)->icon(fn($state)=>$state===Missing::LABEL?Missing::icon():null)
                ->description(fn($state)=>$state===Missing::LABEL?DomainFacts::UNREADABLE_RENEWAL:null)),
            OneLine::apply(TextColumn::make('server_id')->label('Registrar')->state(fn(Domain $record)=>$record->server?$record->server->name.' #'.$record->server_id:Missing::LABEL)
                ->toggleable(isToggledHiddenByDefault:true)
                ->color(fn($state)=>$state===Missing::LABEL?Missing::COLOR:null)->icon(fn($state)=>$state===Missing::LABEL?Missing::icon():null)
                ->description(fn(Domain $record,$state)=>$state===Missing::LABEL?'Registrar account not found. Check Registrars.':(self::REGISTRARS[$record->registrar_key]??null))),
        ])->filters([
            SelectFilter::make('status')->label('Status')->multiple()->options(self::statusOptions())
                ->query(fn(Builder $query,array $data)=>DomainQueries::status($query,$data['values']??[])),
            SelectFilter::make('server_id')->label('Registrar account')->options(function(): array {
                if (!Staff::can()) { return []; }
                return \App\Models\Server::whereIn('id',Account::query()->pluck('server_id'))->orderBy('name')->orderBy('id')->get(['id','name'])->mapWithKeys(fn($server)=>[$server->id=>$server->name.' #'.$server->id])->all();
            }),
            SelectFilter::make('expiring')->label('Expires within')->options(['7'=>'7 days','30'=>'30 days','60'=>'60 days','90'=>'90 days'])
                ->query(function(Builder $query,array $data) {
                    $days=(int)($data['value']??0);
                    return in_array($days,[7,30,60,90],true) ? $query->whereDate('provider_expires_at','>=',CarbonImmutable::today())
                        ->whereDate('provider_expires_at','<=',CarbonImmutable::today()->addDays($days)) : $query;
                }),
            // The account page's Domain alerts row links here with this filter and the account.
            Filter::make('alerts')->label('Open alerts')->toggle()
                ->query(fn(Builder $query)=>DomainQueries::openAlerts($query)),
        ])->recordUrl(fn(Domain $record)=>static::getUrl('view',['record'=>$record]))
            ->defaultSort('domain')->paginated([25,50,100])->defaultPaginationPageOption(25)->searchPlaceholder('Search domain or customer');
    }
    /** Every word has to match the customer's name or email, so a full name finds them. */
    private static function customerSearch(Builder $query,string $search): Builder
    {
        return $query->whereHas('user',function(Builder $user)use($search):void {
            foreach (preg_split('/\s+/',trim($search),flags:PREG_SPLIT_NO_EMPTY) as $word) {
                $user->where(fn(Builder $q)=>$q->where('first_name','like',"%$word%")->orWhere('last_name','like',"%$word%")->orWhere('email','like',"%$word%"));
            }
        });
    }
    /** The domain statuses, in DomainStatus order. */
    public static function statusOptions(): array { return array_map(fn(array $s)=>$s[0],DomainStatus::SET); }
    private const REGISTRARS=['enom'=>'Enom','namesilo'=>'NameSilo'];
    /** Auto-renew stops and sync state per rendered page of records, keyed by domain id: one batch of reads per page. */
    private static ?\WeakMap $rows=null;
    private static function status(Domain $record, mixed $livewire): array
    {
        return DomainFacts::status($record,self::row($record,$livewire)['inputs']);
    }
    private static function tone(Domain $record, mixed $livewire): string
    { return DomainFacts::listTone(self::status($record,$livewire),self::row($record,$livewire)['sync']); }
    private static function note(Domain $record, mixed $livewire): array
    { return DomainFacts::note(self::status($record,$livewire),self::row($record,$livewire)['sync']); }
    private static function registered(Domain $record): bool
    { return DomainFacts::registered($record); }
    /** 'off': true when automatic renewal is off, null when its saved data cannot be read. 'sync': DomainFacts::sync(). */
    private static function row(Domain $record, mixed $livewire): array
    {
        $records=method_exists($livewire,'getTableRecords')?$livewire->getTableRecords():collect([$record]);
        self::$rows??=new \WeakMap();
        $cached=self::$rows[$records]??[];
        if (!array_key_exists($record->id,$cached)) {
            $page=collect(method_exists($records,'items')?$records->items():$records->all())->filter(fn($r)=>$r instanceof Domain);
            if (!$page->contains('id',$record->id)) { $page=collect([$record]); }
            $accounts=Account::whereIn('server_id',$page->pluck('server_id')->unique()->values()->all())->get();
            $stopped=ObservationWork::stoppedIds($page);
            $inputs=StaffDomainInputs::for($page);
            foreach ($page as $d) {
                $account=$accounts->first(fn(Account $a)=>(int)$a->server_id===(int)$d->server_id&&$a->registrar_key===$d->registrar_key
                    &&$a->account_fingerprint===$d->account_fingerprint);
                $cached[$d->id]=['inputs'=>$inputs[$d->id],'off'=>$inputs[$d->id]['off'],'sync'=>DomainFacts::sync($d,$account,isset($stopped[$d->id]),accountLoaded:true)];
            }
            self::$rows[$records]=$cached;
        }
        return $cached[$record->id];
    }
    public static function getRelations(): array { return [RequestsRelationManager::class]; }
    public static function getPages(): array { return ['index'=>ListDomains::route('/'),'adopt'=>AdoptDomain::route('/adopt'),'view'=>ViewDomain::route('/{record}'),'adoption'=>AdoptionPreview::route('/{record}/adoption')]; }
}
