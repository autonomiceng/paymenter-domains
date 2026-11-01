<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff,TldFacts};
/** The History tab of a registrar account: its settings and price changes, newest first. */
class RegistrarHistory extends RegistrarAccount implements HasTable
{
    use InteractsWithTable;
    protected static ?string $slug='registrar-account-history';
    public static function canAccess(): bool { return Staff::can(Staff::POLICY); }
    public static function getRoutePath(Panel $panel): string { return '/registrar-account/{account}/history'; }
    public function content(Schema $schema): Schema { Staff::requireView();return $schema->components([EmbeddedTable::make()]); }
    public function table(Table $table): Table
    {
        return $table->searchable()->searchPlaceholder('Search changes')->defaultSort('time','desc')->emptyStateHeading(TldFacts::history($this->account)===null?DomainFacts::UNREADABLE:'No changes yet')
            ->records(function(?string $search,?string $sortDirection,array $filters,int $page,int $recordsPerPage): LengthAwarePaginator {
                $rows=collect(TldFacts::history($this->account)??[])
                    ->filter(fn(array $r)=>blank($search)||Str::contains($r['change'].' '.$r['reason'].' '.$r['by'],$search,true))
                    ->filter(fn(array $r)=>blank($filters['by']['value']??null)||$r['by']===$filters['by']['value']);
                if($sortDirection==='asc'){$rows=$rows->reverse();}
                return new LengthAwarePaginator($rows->forPage($page,$recordsPerPage),$rows->count(),$recordsPerPage,$page);
            })
            ->filters([SelectFilter::make('by')->label('By')->options(fn()=>collect(TldFacts::history($this->account)??[])->pluck('by','by')->filter()->sort()->all())])
            ->columns([
                TextColumn::make('time')->label('Time')->sortable()->color('gray')->formatStateUsing(fn(string $state)=>new HtmlString(view('domains::partials.moment',['at'=>$state,'fallback'=>$state])->render())),
                TextColumn::make('change')->label('Change'),
                TextColumn::make('reason')->label('Detail')->wrap()->lineClamp(2)->placeholder('None'),
                TextColumn::make('by')->label('By'),
            ]);
    }
}
