<?php
// Adapted from Enom 444f31c RequestsRelationManager; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainHistory, RequestFacts, Staff, StatusMark};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
/** History: registrar requests, staff renewal changes and customer auto-renew changes, newest first. */
class RequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'operations';
    protected static ?string $title = 'History';
    protected static bool $isLazy = false;
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool { return Staff::can(); }
    public function table(Table $table): Table
    {
        Staff::requireView();
        $domain=$this->getOwnerRecord();
        $off=DomainHistory::ordersOff($domain);
        $row=fn(Model|array $r): array=>DomainHistory::row($r,$domain,$off);
        return $table->records(fn(int|string $page,int|string $recordsPerPage)=>self::rows($domain,(int)$page,(int)$recordsPerPage))->columns([
            TextColumn::make('time')->label('Time')->state(fn($record)=>self::utc($row($record)['time']))->default(Missing::LABEL)
                ->formatStateUsing(fn(string $state)=>$state===Missing::LABEL?$state:new HtmlString(view('domains::partials.moment',['at'=>$state,'fallback'=>$state])->render()))
                ->color(fn($state)=>$state===Missing::LABEL?Missing::color(RequestFacts::NO_TIME):null)->icon(fn($state)=>$state===Missing::LABEL?Missing::icon(RequestFacts::NO_TIME):null)
                ->description(fn($state)=>$state===Missing::LABEL?RequestFacts::NO_TIME:null),
            TextColumn::make('event')->label('Event')->state(fn($record)=>$row($record)['event'])->description(fn($record)=>$row($record)['sub'])->wrap(),
            TextColumn::make('status')->label('Status')->badge()->state(fn($record)=>$row($record)['status']['label']??null)->color(fn($record)=>$row($record)['status']['tone']??'gray')
                ->extraAttributes(fn($record)=>StatusMark::attributes($row($record)['status']['tone']??'gray',$record instanceof Operation?'request-'.$record->id:null)),
            TextColumn::make('by')->label('By')->state(fn($record)=>$row($record)['by'])->color('gray'),
        ])->recordAction(null)->recordUrl(fn($record)=>$record instanceof Operation?OperationResource::getUrl('view',['record'=>$record]):null)
            ->emptyStateHeading('No history yet')->emptyStateDescription(null)->paginated([25,50,100])->defaultPaginationPageOption(25);
    }
    private static function rows(Domain $domain, int $page, int $perPage): LengthAwarePaginator
    {
        $entries=DomainHistory::entries($domain);
        $perPage=max(1,$perPage); $page=max(1,$page);
        return new LengthAwarePaginator(array_slice($entries,($page-1)*$perPage,$perPage),count($entries),$perPage,$page);
    }
    private static function utc(?\DateTimeInterface $time): ?string
    { return $time?\DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y H:i').' UTC':null; }
}
