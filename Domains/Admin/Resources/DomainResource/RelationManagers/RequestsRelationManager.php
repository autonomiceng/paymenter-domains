<?php
// Adapted from Enom 444f31c RequestsRelationManager; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainQueries, Labels, RequestFacts, Staff};
class RequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'operations';
    protected static ?string $title = 'Activity';
    protected static bool $isLazy = false;
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool { return Staff::can(); }
    public function table(Table $table): Table
    {
        Staff::requireView();
        return $table->query(fn()=>DomainQueries::operations($this->getOwnerRecord()))->columns([
            TextColumn::make('id')->label('Request')->prefix('#')->sortable()->wrap()
                ->description(fn($record)=>'Created '.($record->created_at?->format('M j, Y')??'Unknown')),
            TextColumn::make('action')->label('Request type')->formatStateUsing(fn($state)=>Labels::action($state)),
            TextColumn::make('state')->label('Status')->badge()->formatStateUsing(fn($state)=>Labels::state($state))->color(fn($state)=>Labels::color($state))
                ->description(fn($record)=>$record->safe_code===null ? null : Labels::reason($record->safe_code))->wrap(),
            TextColumn::make('review')->label('Review')->state(fn($record)=>RequestFacts::review($record))->listWithLineBreaks()->wrap()
                ->extraAttributes(['style'=>'min-width:14rem;max-width:32rem;overflow-wrap:anywhere']),
        ])->recordUrl(fn($record)=>\Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource::getUrl('view',['record'=>$record]))->defaultSort('id','desc')->paginated([10,25,50])->defaultPaginationPageOption(10);
    }
}
