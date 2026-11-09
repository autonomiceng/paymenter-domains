<?php
// Adapted from Enom 444f31c RequestsRelationManager; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainHistory, HistoryTable, Staff, StaffDomainStatus};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** History: registrar requests, staff renewal changes and customer auto-renew changes, newest first. */
class RequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'operations';
    protected static ?string $title = 'History';
    protected static bool $isLazy = false;
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool { return Staff::can(); }
    /** The request the domain page head names, and the head's words, passed by the page (ViewDomain::getRelationManagers). */
    #[\Livewire\Attributes\Locked]
    public ?int $headRequest=null;
    #[\Livewire\Attributes\Locked]
    public ?string $headSaid=null;
    public function table(Table $table): Table
    {
        Staff::requireView();
        $domain=$this->getOwnerRecord();
        return HistoryTable::apply($table,fn()=>self::rows($domain,$this->headRequest,$this->headSaid),'No history yet')->heading(null)->recordAction(null);
    }
    /** The request the head names keeps its status here, but not the reason the head already says (DomainHistory::repeatsHead).
     * @return list<array{time:?string,change:string,reason:?string,by:string,url:?string}> */
    private static function rows(Domain $domain, ?int $request, ?string $said): array
    {
        $off=DomainHistory::ordersOff($domain);
        return array_map(function(Operation|array $entry)use($domain,$off,$request,$said): array {
            $row=DomainHistory::row($entry,$domain,$off);
            $sub=$row['sub']?StaffDomainStatus::sentence($row['sub']):null;
            if (DomainHistory::repeatsHead($entry,$sub,$request,$said)) { $sub=null; }
            $detail=array_filter([$row['status']['label']??null,$sub]);
            return ['time'=>self::utc($row['time']),'change'=>$row['event'],'reason'=>$detail===[]?null:implode(' ',array_map(StaffDomainStatus::sentence(...),$detail)),
                'by'=>$row['by'],'url'=>$entry instanceof Operation?OperationResource::getUrl('view',['record'=>$entry]):null];
        },DomainHistory::entries($domain));
    }
    private static function utc(?\DateTimeInterface $time): ?string
    { return $time?\DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y H:i').' UTC':null; }
}
