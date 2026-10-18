<?php
// Adapted from Enom 444f31c OperationResource; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Resources\Resource;
use Filament\Tables\{Columns\TextColumn, Filters\Filter, Filters\SelectFilter, Table};
use Illuminate\Database\Eloquent\{Builder,Model};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels,OperationQueries,PaidUncertainty,RefundCandidates,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\{ListOperations,ViewOperation};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
class OperationResource extends Resource
{
    protected static ?string $model=Operation::class;
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-arrows-right-left';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?string $navigationLabel='Registrar requests';
    protected static ?string $modelLabel='registrar request';
    protected static ?string $pluralModelLabel='registrar requests';
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
        return $table->columns([
            TextColumn::make('id')->label('Request')->prefix('#')->sortable(),
            TextColumn::make('domain.domain')->label('Domain')->searchable()->wrap()
                ->extraAttributes(['style'=>'max-width:22rem;overflow-wrap:anywhere']),
            TextColumn::make('action')->label('Request type')->formatStateUsing(fn($state)=>Labels::action($state))->visibleFrom('xl'),
            TextColumn::make('state')->label('Status')->badge()->color(fn($state)=>Labels::color($state))
                ->formatStateUsing(fn($state)=>Labels::state($state))->wrap()
                ->description(fn($record)=>$record->safe_code===null?null:Labels::reason($record->safe_code)),
            TextColumn::make('paid_hold')->label('Spending / outcome review')->state(fn($record)=>PaidUncertainty::holdSummary($record)??PaidUncertainty::summary($record))->wrap()
                ->extraAttributes(['style'=>'max-width:24rem;overflow-wrap:anywhere']),
            TextColumn::make('review')->label('Review')->state(fn($record)=>RequestFacts::review($record))->listWithLineBreaks()->wrap()
                ->extraAttributes(['style'=>'max-width:30rem;overflow-wrap:anywhere'])->visibleFrom('xl'),
        ])->filters([
            SelectFilter::make('state')->label('Status')->multiple()->options(['held'=>'On hold','uncertain'=>'Unconfirmed',
                'pending'=>'In progress','running'=>'Sending','rejected'=>'Rejected','succeeded'=>'Completed']),
            Filter::make('held')->label('On hold')->query(fn(Builder $query)=>$query->where('state','held')),
            Filter::make('unresolved')->label('Awaiting reconciliation')->query(fn(Builder $query)=>$query->whereIn('state',['uncertain','pending','running'])),
            Filter::make('credit_review')->label('Customer credit review')->query(fn(Builder $query)=>RefundCandidates::query($query)),
            Filter::make('failed_transfer')->label('Failed transfers')->query(fn(Builder $query)=>$query->where('action','transfer')->where('state','rejected')),
            Filter::make('paid_uncertainty')->label('Paid outcome review')->query(fn(Builder $query)=>OperationQueries::paidUncertainty($query)),
        ])->recordUrl(fn($record)=>static::getUrl('view',['record'=>$record]))->defaultSort('id','desc')
            ->paginated([25,50,100])->defaultPaginationPageOption(25)
            ->emptyStateHeading('No registrar requests')->emptyStateDescription('Requests appear when domains are ordered, renewed or changed.');
    }
    public static function getPages(): array
    { return ['index'=>ListOperations::route('/'),'view'=>ViewOperation::route('/{record}')]; }
}
