<?php
// Adapted from Enom 444f31c OperationResource; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Resources\Resource;
use Filament\Tables\{Columns\TextColumn, Filters\SelectFilter, Table};
use Illuminate\Database\Eloquent\{Builder,Model};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels,OperationQueries,Staff};
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
    protected static ?int $navigationSort=2;
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
    { Staff::requireView(); return OperationQueries::bound(parent::getEloquentQuery()); }
    public static function table(Table $table): Table
    {
        Staff::requireView();
        $statuses=array_map(fn($status)=>$status[0],RequestStatus::SET); $servers=\App\Models\Server::pluck('name','id')->all();
        return $table->columns([
            TextColumn::make('id')->label('Request')->formatStateUsing(fn($state)=>'Request #'.$state)->sortable()
                ->suffix(fn(Operation $record)=>self::demo($record)?DemoData::tag(inline:true):null),
            TextColumn::make('domain.domain')->label('Domain')->searchable()->wrap()->fontFamily('mono')
                ->formatStateUsing(fn($state)=>\Paymenter\Extensions\Servers\Domains\Presentation\DomainName::html((string)$state))
                ->extraAttributes(['style'=>'overflow-wrap:break-word;min-width:11rem;max-width:16rem']),
            TextColumn::make('action')->label('Type')->formatStateUsing(fn($state)=>Labels::action($state)),
            TextColumn::make('state')->label('Status')->badge()->color(fn($record)=>Labels::requestStatus($record)['tone'])
                ->formatStateUsing(fn($record)=>Labels::requestStatus($record)['label'])->wrap()
                ->description(fn($record)=>Labels::requestStatus($record)['reason']),
            TextColumn::make('created_at')->label('Created')->dateTime('M j, Y H:i \\U\\T\\C','UTC')->sortable()->visibleFrom('lg'),
            TextColumn::make('server_id')->label('Registrar account')->visibleFrom('xl')->formatStateUsing(fn($state)=>$servers[$state]??Missing::LABEL)
                ->color(fn($state)=>isset($servers[$state])?null:Missing::COLOR)->description(fn($record)=>isset($servers[$record->server_id])?null:'Registrar account not found'),
        ])->filters([
            SelectFilter::make('status')->label('Status')->multiple()->options($statuses)
                ->query(fn(Builder $query,array $data)=>$data['values']??[] ? $query->whereIn('state',array_keys(array_filter(RequestStatus::STATES,fn($state)=>in_array($state[0],$data['values'],true)))) : $query),
            SelectFilter::make('action')->label('Type')->multiple()
                ->options(fn()=>collect(Operation::query()->distinct()->pluck('action'))->mapWithKeys(fn($action)=>[$action=>Labels::action($action)])->sort()->all()),
            SelectFilter::make('server_id')->label('Registrar account')->multiple()
                ->options(fn()=>\App\Models\Server::whereIn('id',Operation::query()->distinct()->pluck('server_id'))->pluck('name','id')->all()),
        ])->recordUrl(fn($record)=>static::getUrl('view',['record'=>$record]))->defaultSort('id','desc')
            ->paginated([25,50,100])->defaultPaginationPageOption(25)
            ->emptyStateHeading('No registrar requests')->emptyStateDescription(null);
    }
    /** Demo requests keep the Demo tag; a row whose saved data can't be read still lists. */
    public static function demo(Operation $op): bool { try { return DemoData::is($op); } catch (\Throwable) { return false; } }
    public static function getPages(): array
    { return ['index'=>ListOperations::route('/'),'view'=>ViewOperation::route('/{record}')]; }
}
