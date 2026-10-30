<?php
// Adapted from Enom 444f31c RequestsRelationManager; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainQueries, Labels, RequestFacts, Staff};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation, WatchAudit};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
/** History: registrar requests, staff renewal changes and customer auto-renew changes, newest first. */
class RequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'operations';
    protected static ?string $title = 'History';
    protected static bool $isLazy = false;
    private const HOLDINGS = ['stop' => 'Renewal stopped', 'resume' => 'Renewal resumed', 'reprice' => 'Renewal price updated'];
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool { return Staff::can(); }
    public function table(Table $table): Table
    {
        Staff::requireView();
        $domain=$this->getOwnerRecord();
        $row=fn(Model|array $r): array=>$r instanceof Operation?self::request($r):$r;
        return $table->records(fn(int|string $page,int|string $recordsPerPage)=>self::rows($domain,(int)$page,(int)$recordsPerPage))->columns([
            TextColumn::make('time')->label('Time')->state(fn($record)=>self::utc($row($record)['time']))->default(Missing::LABEL)
                ->color(fn($state)=>$state===Missing::LABEL?Missing::color(RequestFacts::NO_TIME):null)->icon(fn($state)=>$state===Missing::LABEL?Missing::icon(RequestFacts::NO_TIME):null)
                ->description(fn($state)=>$state===Missing::LABEL?RequestFacts::NO_TIME:null),
            TextColumn::make('event')->label('Event')->state(fn($record)=>$row($record)['event'])->description(fn($record)=>$row($record)['sub'])->wrap()
                ->extraAttributes(['style'=>'min-width:14rem;max-width:32rem;overflow-wrap:anywhere']),
            TextColumn::make('status')->label('Status')->badge()->state(fn($record)=>$row($record)['status']['label']??null)->color(fn($record)=>$row($record)['status']['tone']??'gray'),
            TextColumn::make('by')->label('By')->state(fn($record)=>$row($record)['by'])->color('gray'),
        ])->recordAction(null)->recordUrl(fn($record)=>$record instanceof Operation?OperationResource::getUrl('view',['record'=>$record]):null)
            ->emptyStateHeading('No history yet')->emptyStateDescription(null)->paginated([10,25,50])->defaultPaginationPageOption(10);
    }
    private static function rows(Domain $domain, int $page, int $perPage): LengthAwarePaginator
    {
        $rows=DomainQueries::operations($domain)->orderByDesc('id')->get()->all();
        $holdings=[]; $audits=[];
        try { $holdings=$domain->renewal_policy['staff_holdings']??[]; } catch (\Throwable) {}
        $holdings=is_array($holdings)&&array_is_list($holdings)?array_filter($holdings,'is_array'):[];
        try { $audits=WatchAudit::query()->where('subject_type','domain')->where('subject_id',$domain->id)->where('event','owner_auto_renew')->orderByDesc('id')->limit(100)->get(); }
        catch (\Throwable) { $audits=collect(); }
        $ids=array_unique(array_filter([...array_map(fn(array $h)=>is_int($h['actor_id']??null)?$h['actor_id']:null,$holdings),...$audits->pluck('actor_id')->all()]));
        $names=$ids?User::query()->whereIn('id',$ids)->get(['id','first_name','last_name'])->mapWithKeys(fn(User $u)=>[$u->id=>trim($u->first_name.' '.$u->last_name)])->all():[];
        foreach (array_values($holdings) as $i=>$h) {
            $rows[]=['__key'=>'holding-'.$i,'time'=>self::at($h['at']??null),'event'=>self::HOLDINGS[$h['action']??'']??'Renewal changed',
                'sub'=>is_string($h['reason']??null)?$h['reason']:null,'status'=>null,'by'=>$names[$h['actor_id']??0]??'Staff'];
        }
        foreach ($audits as $a) {
            $enabled=null; try { $enabled=$a->data['enabled']??null; } catch (\Throwable) {}
            $rows[]=['__key'=>'audit-'.$a->id,'time'=>$a->created_at,'event'=>$enabled===null?'Auto-renew changed':'Auto-renew turned '.($enabled?'on':'off'),
                'sub'=>null,'status'=>null,'by'=>$names[$a->actor_id]??'Customer'];
        }
        $at=fn(Model|array $r)=>($r instanceof Operation?$r->created_at:$r['time'])?->getTimestamp()??PHP_INT_MIN;
        usort($rows,fn($a,$b)=>$at($b)<=>$at($a));
        $perPage=max(1,$perPage); $page=max(1,$page);
        return new LengthAwarePaginator(array_slice($rows,($page-1)*$perPage,$perPage),count($rows),$perPage,$page);
    }
    private static function request(Operation $op): array
    {
        return ['time'=>$op->created_at,'event'=>'Request #'.$op->id.' '.Labels::action($op->action),
            'sub'=>Labels::requestStatus($op)['reason'],
            'status'=>Labels::requestStatus($op),'by'=>'System'];
    }
    private static function utc(?\DateTimeInterface $time): ?string
    { return $time?\DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y H:i').' UTC':null; }
    private static function at(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)||str_contains($value,"\0")) { return null; }
        try { return new \DateTimeImmutable($value); } catch (\Throwable) { return null; }
    }
}
