<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
/** The History tab's table: Time, Change, Detail, By. A row's url, when it has one, opens its request. */
final class HistoryTable
{
    /** @param \Closure():?array $read Null means the saved history can't be read. */
    public static function apply(Table $table,\Closure $read): Table
    {
        $saved=false;$read=function()use(&$saved,$read):?array {return $saved===false?($saved=$read()):$saved;};
        return $table->searchable()->searchPlaceholder('Search changes')->defaultSort('time','desc')->emptyStateHeading(fn()=>$read()===null?DomainFacts::UNREADABLE:'No changes yet')
            ->records(function(?string $search,?string $sortDirection,array $filters,int $page,int $recordsPerPage)use($read): LengthAwarePaginator {
                $rows=collect($read()??[])
                    ->filter(fn(array $r)=>blank($search)||Str::contains($r['change'].' '.$r['reason'].' '.$r['by'],$search,true))
                    ->filter(fn(array $r)=>blank($filters['by']['value']??null)||$r['by']===$filters['by']['value']);
                if($sortDirection==='asc'){$rows=$rows->reverse();}
                return new LengthAwarePaginator($rows->forPage($page,$recordsPerPage),$rows->count(),$recordsPerPage,$page);
            })
            ->filters([SelectFilter::make('by')->label('By')->options(fn()=>collect($read()??[])->pluck('by','by')->filter()->sort()->all())])
            ->columns([
                TextColumn::make('time')->label('Time')->sortable()->default(Missing::LABEL)
                    ->formatStateUsing(fn(string $state)=>$state===Missing::LABEL?$state:Sheet::moment($state))
                    ->color(fn($state)=>$state===Missing::LABEL?Missing::color(RequestFacts::NO_TIME):'gray')->icon(fn($state)=>$state===Missing::LABEL?Missing::icon(RequestFacts::NO_TIME):null)
                    ->description(fn($state)=>$state===Missing::LABEL?RequestFacts::NO_TIME:null),
                TextColumn::make('change')->label('Change'),
                TextColumn::make('reason')->label('Detail')->wrap()->lineClamp(2)->placeholder('None'),
                TextColumn::make('by')->label('By'),
            ])->recordUrl(fn(array $record)=>$record['url']??null)->paginated([25,50,100])->defaultPaginationPageOption(25);
    }
}
