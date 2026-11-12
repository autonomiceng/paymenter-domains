<?php
// Adapted from Enom 444f31c OperationResource; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Resources\Resource;
use Filament\Tables\{Columns\TextColumn, Filters\SelectFilter, Table};
use Illuminate\Database\Eloquent\{Builder,Model};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{EmptyTable,Labels,NavigationCount,OneLine,OperationQueries,RequestFacts,Staff,StatusMark,TableColumns};
use Paymenter\Extensions\Servers\Domains\Presentation\{Missing,RequestStatus};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\{ListOperations,ViewOperation};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
class OperationResource extends Resource
{
    protected static ?string $model=Operation::class;
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-arrows-right-left';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?string $navigationLabel='Registrar requests';
    protected static bool $shouldRegisterNavigation=false;
    protected static ?string $modelLabel='registrar request';
    protected static ?string $pluralModelLabel='registrar requests';
    // Sentence case: Filament's default breadcrumb title-cases the plural label.
    protected static ?string $breadcrumb='Registrar requests';
    protected static ?string $slug='registrar-requests';
    public static function canViewAny(): bool { return Staff::can(); }
    public static function canView(Model $record): bool { return Staff::can(); }
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }
    public static function canGloballySearch(): bool { return false; }
    public static function getEloquentQuery(): Builder
    { Staff::requireView(); return OperationQueries::bound(parent::getEloquentQuery())->with('invoice:id,status'); }
    /** Statuses that wait on staff. Awaiting payment waits on the customer, and In progress on the registrar. */
    public const NEEDS_STAFF=['on_hold','under_review'];
    /** Menu count: requests On hold or Under review, the list's Status filter for those two. Null at zero, so the menu shows nothing. */
    public static function getNavigationBadge(): ?string
    {
        if (!Staff::can()) { return null; }
        return NavigationCount::read('requests',fn()=>OperationQueries::status(static::getEloquentQuery(),self::NEEDS_STAFF)->count());
    }
    public static function getNavigationBadgeUrl(): string { return static::getUrl('index',['filters'=>['status'=>['values'=>self::NEEDS_STAFF]]]); }
    public static function getNavigationBadgeTooltip(): ?string { return 'Registrar requests on hold or under review'; }
    public static function getNavigationBadgeColor(): string { return 'warning'; }
    public static function table(Table $table): Table
    {
        $grants=Staff::requireView();
        $statuses=array_map(fn($status)=>$status[0],RequestFacts::STATUSES); $servers=\App\Models\Server::pluck('name','id')->all();
        // One read of the Orders switches: the Status filter and every row's words use it, so they agree even if a switch changes meanwhile.
        $off=OperationQueries::ordersOffAccounts();
        $status=fn(Operation $record)=>RequestFacts::status($record,OperationQueries::ordersOff($record,$off),grants:$grants,accounts:$off);
        EmptyTable::apply($table,'No registrar requests','Requests show here when a customer orders a domain.','No requests match');
        return TableColumns::choose($table,[
            TextColumn::make('id')->label('Request')->formatStateUsing(fn($state)=>'Request #'.$state)->sortable()
                ->suffix(fn(Operation $record)=>self::demo($record)?DemoData::tag(inline:true):null),
            // Status sits beside the request number so a 390px phone shows both before the table scrolls.
            TextColumn::make('state')->label('Status')->badge()->color(fn($record)=>$status($record)['tone'])
                ->extraAttributes(fn($record)=>StatusMark::attributes($status($record)['tone'],'request-'.$record->id))
                ->formatStateUsing(fn($record)=>$status($record)['label']),
            OneLine::apply(TextColumn::make('domain.domain')->label('Domain')->searchable()->fontFamily('mono'),OneLine::NAME),
            TextColumn::make('action')->label('Type')->formatStateUsing(fn($state)=>Labels::action($state)),
            OneLine::apply(TextColumn::make('note')->label('Note')->color('gray')->state(fn($record)=>RequestFacts::reason($record,OperationQueries::ordersOff($record,$off),$grants,$off)),OneLine::NOTE),
            TextColumn::make('created_at')->label('Created')->formatStateUsing(fn($state)=>\Paymenter\Extensions\Servers\Domains\Admin\Support\Sheet::moment($state->getTimestamp()))->sortable()->visibleFrom('lg'),
            OneLine::apply(TextColumn::make('server_id')->label('Registrar account')->visibleFrom('xl')->formatStateUsing(fn($state)=>$servers[$state]??Missing::LABEL)
                ->color(fn($state)=>isset($servers[$state])?null:Missing::COLOR)->description(fn($record)=>isset($servers[$record->server_id])?null:'Registrar account not found. Check Registrars.')),
        // The account starts under Columns so the default columns fit inside the card at 1440px; the request page and the filter name it.
        ],['server_id'])->filters([
            SelectFilter::make('status')->label('Status')->multiple()->options($statuses)
                ->query(fn(Builder $query,array $data)=>OperationQueries::status($query,$data['values']??[],$off)),
            SelectFilter::make('action')->label('Type')->multiple()
                ->options(fn()=>collect(Operation::query()->distinct()->pluck('action'))->mapWithKeys(fn($action)=>[$action=>Labels::action($action)])->sort()->all()),
            SelectFilter::make('server_id')->label('Registrar account')->multiple()
                ->options(fn()=>\App\Models\Server::whereIn('id',Operation::query()->distinct()->pluck('server_id'))->pluck('name','id')->all()),
        ])->recordUrl(fn($record)=>static::getUrl('view',['record'=>$record]))->defaultSort('id','desc')
            ->paginated([25,50,100])->defaultPaginationPageOption(25)->searchPlaceholder('Search domain');
    }
    /** Demo requests keep the Demo tag; a row whose saved data can't be read still lists. */
    public static function demo(Operation $op): bool { try { return DemoData::is($op); } catch (\Throwable) { return false; } }
    public static function getPages(): array
    { return ['index'=>ListOperations::route('/'),'view'=>ViewOperation::route('/{record}')]; }
}
