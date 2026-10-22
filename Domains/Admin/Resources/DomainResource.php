<?php
// Adapted from Enom 444f31c DomainResource; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Carbon\CarbonImmutable;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\{Filter, SelectFilter};
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\{Builder, Model};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainQueries, Labels, Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{AdoptDomain, AdoptionPreview, ListDomains, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain};
class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';
    protected static string|\UnitEnum|null $navigationGroup = 'Domains';
    protected static ?string $navigationLabel = 'Domains';
    protected static ?string $modelLabel = 'domain';
    protected static ?string $pluralModelLabel = 'domains';
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
        return $table->modifyQueryUsing(fn (Builder $query) => $query->with(['user:id,email', 'server:id,name', 'latestAcquisition:domains_operations.id,domains_operations.domain_id,domains_operations.action,domains_operations.state,domains_operations.authority_generation,domains_operations.account_fingerprint']))->columns([
            TextColumn::make('domain')->label('Domain')->searchable()->sortable()->wrap()
                ->description(fn(Domain $record)=>$record->user?->email ?? 'Customer #'.$record->user_id)
                ->tooltip(fn(Domain $record)=>'Customer #'.$record->user_id)
                ->extraAttributes(['style'=>'overflow-wrap:anywhere;max-width:24rem']),
            TextColumn::make('registrar_key')->label('Registrar')->formatStateUsing(fn($state)=>match($state) {'enom'=>'Enom','namesilo'=>'NameSilo',default=>'Unknown'})
                ->description(fn(Domain $record)=>$record->server?->name ?? 'Server #'.$record->server_id),
            TextColumn::make('status')->badge()->tooltip('See Registrar requests')->formatStateUsing(fn($state,Domain $record)=>in_array(self::listState($record),['held','uncertain'],true)?Labels::state(self::listState($record)):Labels::status(self::listState($record)))->color(fn($state,Domain $record)=>Labels::color(self::listState($record))),
            TextColumn::make('payment_review')->label('Payment review')->state(fn(Domain $record)=>\Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionPaymentReview::count($record))
                ->formatStateUsing(fn($state)=>$state ? $state.($state===1?' activity':' activities') : 'None')->badge()->color(fn($state)=>$state ? 'warning' : 'gray'),
            TextColumn::make('provider_expires_at')->label('Expires')->state(fn(Domain $record)=>$record->provider_expires_at?->format('M j, Y') ?? 'Unconfirmed')
                ->sortable(query: fn(Builder $query,string $direction)=>$query->orderBy('provider_expires_at',$direction)),
        ])->filters([
            SelectFilter::make('status')->label('Status')->multiple()->options([...Labels::statuses(),'held'=>Labels::state('held'),'uncertain'=>Labels::state('uncertain')])
                ->query(fn(Builder $query,array $data)=>DomainQueries::status($query,$data['values']??[])),
            SelectFilter::make('server_id')->label('Registrar account')->options(function(): array {
                if (!Staff::can()) { return []; }
                $accounts=Account::orderBy('server_id')->get();
                $names=\App\Models\Server::whereIn('id',$accounts->pluck('server_id'))->pluck('name','id');
                return $accounts->mapWithKeys(fn($a)=>[$a->server_id=>match($a->registrar_key) {'enom'=>'Enom','namesilo'=>'NameSilo',default=>'Unknown'}.' · '.($names[$a->server_id] ?? 'Server #'.$a->server_id)])->all();
            }),
            SelectFilter::make('expiring')->label('Expiring within')->options(['7'=>'7 days','30'=>'30 days','60'=>'60 days','90'=>'90 days'])
                ->query(function(Builder $query,array $data) {
                    $days=(int)($data['value']??0);
                    return in_array($days,[7,30,60,90],true) ? $query->whereDate('provider_expires_at','>=',CarbonImmutable::today())
                        ->whereDate('provider_expires_at','<=',CarbonImmutable::today()->addDays($days)) : $query;
                }),
            Filter::make('expired')->label('Expired')->query(fn(Builder $query)=>$query->whereDate('provider_expires_at','<',CarbonImmutable::today())),
            Filter::make('held')->label('On hold')->query(fn(Builder $query)=>DomainQueries::attention($query,['held'])),
            Filter::make('late_payment')->label('Paid after order closed')->query(fn(Builder $query)=>\Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionPaymentReview::query($query)),
            Filter::make('credit_review')->label('Customer credit review')->query(fn(Builder $query)=>DomainQueries::creditReview($query)),
            Filter::make('registrar_rejected')->label('Registrar rejected transfers')->query(fn(Builder $query)=>DomainQueries::registrarRejected($query)),
            Filter::make('unresolved')->label('In progress or unconfirmed')->query(fn(Builder $query)=>DomainQueries::attention($query,['uncertain','running','pending'])),
        ])->recordUrl(fn(Domain $record)=>static::getUrl('view',['record'=>$record]))
            ->defaultSort('domain')->paginated([25,50,100])->defaultPaginationPageOption(25)
            ->emptyStateHeading('No domains yet')->emptyStateDescription('Domains appear here when customers order them.');
    }
    private static function listState(Domain $record): string
    {
        if ($record->adoptionPending()) { return 'adoption_pending'; }
        $state=$record->status==='needs_review' ? $record->latestAcquisition?->state : null;
        return in_array($state,['held','uncertain'],true) ? $state : $record->status;
    }
    public static function getRelations(): array { return [RequestsRelationManager::class]; }
    public static function getPages(): array { return ['index'=>ListDomains::route('/'),'adopt'=>AdoptDomain::route('/adopt'),'view'=>ViewDomain::route('/{record}'),'adoption'=>AdoptionPreview::route('/{record}/adoption')]; }
}
