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
    public function table(Table $table): Table
    {
        Staff::requireView();
        $domain=$this->getOwnerRecord();
        return HistoryTable::apply($table,fn()=>self::rows($domain))->heading(null)->emptyStateHeading('No history yet')->recordAction(null);
    }
    /** @return list<array{time:?string,change:string,reason:?string,by:string,url:?string}> */
    private static function rows(Domain $domain): array
    {
        $off=DomainHistory::ordersOff($domain);
        return array_map(function(Operation|array $entry)use($domain,$off): array {
            $row=DomainHistory::row($entry,$domain,$off);
            $detail=array_filter([$row['status']['label']??null,$row['sub']]);
            return ['time'=>self::utc($row['time']),'change'=>$row['event'],'reason'=>$detail===[]?null:implode(' ',array_map(StaffDomainStatus::sentence(...),$detail)),
                'by'=>$row['by'],'url'=>$entry instanceof Operation?OperationResource::getUrl('view',['record'=>$entry]):null];
        },DomainHistory::entries($domain));
    }
    private static function utc(?\DateTimeInterface $time): ?string
    { return $time?\DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y H:i').' UTC':null; }
}
