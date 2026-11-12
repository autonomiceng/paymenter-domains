<?php
// Adapted from Enom 444f31c RequestsRelationManager; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation, WatchAudit};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview;
/** One domain's history: registrar requests, staff holds, releases and renewal changes, and customer auto-renew changes, newest first. Read by the History tab and the Activity rail. */
final class DomainHistory
{
    private const HOLDINGS = ['stop' => 'Renewal invoices turned off', 'resume' => 'Renewal invoices turned on', 'reprice' => 'Renewal price updated', 'resume_observation' => 'Sync resumed'];
    /** @return list<Operation|array> */
    public static function entries(Domain $domain): array
    {
        $rows=DomainQueries::operations($domain)->with('invoice:id,status')->orderByDesc('id')->get()->all();
        foreach ($rows as $op) {
            if(is_array($op)){continue;}
            try { $history=$op->policy['staff_actions']??[]; } catch (\Throwable) { continue; }
            if (!is_array($history)||!array_is_list($history)||count($history)>100) { continue; }
            foreach ($history as $i=>$action) {
                if (!is_array($action)||!in_array($action['action']??null,['hold','release','resume_observation'],true)||($action['phase']??null)!=='completed') { continue; }
                $rows[]=['__key'=>$action['action'].'-'.$op->id.'-'.$i,'__request'=>$op->id,'__order'=>$i+1,'time'=>self::at($action['at']??null),'event'=>match($action['action']){'hold'=>'Put on hold','release'=>'Hold released','resume_observation'=>'Sync resumed'},
                    'sub'=>'Request #'.$op->id.' '.Labels::action($op->action),'status'=>null,'by'=>is_string($action['actor_name']??null)&&$action['actor_name']!==''?$action['actor_name']:'Staff'];
            }
        }
        $holdings=[]; $audits=[];
        try { $holdings=$domain->renewal_policy['staff_holdings']??[]; } catch (\Throwable) {}
        $holdings=is_array($holdings)&&array_is_list($holdings)?array_filter($holdings,'is_array'):[];
        try { $audits=WatchAudit::query()->where('subject_type','domain')->where('subject_id',$domain->id)->where('event','owner_auto_renew')->orderByDesc('id')->limit(100)->get(); }
        catch (\Throwable) { $audits=collect(); }
        try { $sync=$domain->observation['sync_history']??[]; } catch (\Throwable) { $sync=[]; }
        $sync=is_array($sync)?array_filter(array_slice($sync,-20),'is_array'):[];
        $ids=array_unique(array_filter([...array_map(fn(array $h)=>is_int($h['actor_id']??null)?$h['actor_id']:null,[...$holdings,...$sync]),...$audits->pluck('actor_id')->all()]));
        $names=$ids?User::query()->whereIn('id',$ids)->get(['id','first_name','last_name'])->mapWithKeys(fn(User $u)=>[$u->id=>trim($u->first_name.' '.$u->last_name)])->all():[];
        foreach (array_values($holdings) as $i=>$h) {
            $rows[]=['__key'=>'holding-'.$i,'time'=>self::at($h['at']??null),'event'=>self::HOLDINGS[$h['action']??'']??'Renewal changed',
                'sub'=>is_string($h['reason']??null)&&$h['reason']!==(StaffOperationReview::ACTIONS[$h['action']??'']??null)?$h['reason']:null,'status'=>null,'by'=>$names[$h['actor_id']??0]??'Staff'];
        }
        foreach ($audits as $a) {
            $enabled=null; try { $enabled=$a->data['enabled']??null; } catch (\Throwable) {}
            $rows[]=['__key'=>'audit-'.$a->id,'time'=>$a->created_at,'event'=>$enabled===null?'Auto-renew changed':'Auto-renew turned '.($enabled?'on':'off'),
                'sub'=>null,'status'=>null,'by'=>$names[$a->actor_id]??'Customer'];
        }
        $events=['Sync requested','Domain synced',"Couldn't read all records",'Records not read',"Couldn't sync domain",'Sync stopped','Sync needs a check'];
        foreach (array_reverse($sync,true) as $i=>$entry) {
            if (!in_array($entry['event']??null,$events,true)) { continue; }
            $rows[]=['__key'=>'sync-'.$i,'__order'=>$i+1,'time'=>self::at($entry['at']??null),'event'=>$entry['event'],'sub'=>null,'status'=>null,
                'by'=>is_string($entry['actor_name']??null)&&$entry['actor_name']!==''?$entry['actor_name']:($names[$entry['actor_id']??0]??'Staff')];
        }
        $at=fn(Operation|array $r)=>($r instanceof Operation?self::when($r):$r['time'])?->getTimestamp()??PHP_INT_MIN;
        $order=static fn(Operation|array $r)=>$r instanceof Operation?[$r->id,0]:[$r['__request']??0,$r['__order']??0];
        usort($rows,fn($a,$b)=>($at($b)<=>$at($a))?:($order($b)<=>$order($a)));
        return $rows;
    }
    public static function ordersOff(Domain $domain): bool
    { return !Account::where('server_id',$domain->server_id)->value('processing_enabled'); }
    /** A request row's time: when it was sent while it waits on the registrar (the request page's Sent row), else when it was created. */
    public static function when(Operation $op): ?\DateTimeInterface
    { return $op->started_at!==null&&in_array($op->state,['running','pending','uncertain'],true)?$op->started_at:$op->created_at; }
    /** @return array{time:?\DateTimeInterface,event:string,sub:?string,status:?array,by:string} */
    public static function row(Operation|array $entry, Domain $domain, bool $ordersOff): array
    {
        if (is_array($entry)) { return $entry; }
        $reason=RequestFacts::reason($entry,$ordersOff);
        $dsAction=in_array($entry->action,['ds_add','ds_delete'],true);
        $idle=false;
        if($dsAction&&$entry->state==='queued'&&$ordersOff){
            try { $idle=\Paymenter\Extensions\Servers\Domains\Services\ManagementEvidence::idle($entry); }
            catch (\Illuminate\Contracts\Encryption\DecryptException) { /* Unreadable evidence needs the DS review state. */ }
        }
        if($dsAction&&!$idle){
            $ds=\Paymenter\Extensions\Servers\Domains\Presentation\DsState::event($entry,$domain,true);
            return ['time'=>self::when($entry),'event'=>'Request #'.$entry->id.' DNSSEC','sub'=>$ds['text'],'status'=>['label'=>$ds['state'],'tone'=>$ds['tone']],'by'=>self::requester($entry,$domain)];
        }
        if ($entry->state==='queued'&&$ordersOff&&$reason!==null) { $reason=StaffDomainStatus::sentence($reason).RequestFacts::ordersStep($reason); }
        return ['time'=>self::when($entry),'event'=>'Request #'.$entry->id.' '.Labels::action($entry->action),
            'sub'=>$reason,
            'status'=>RequestFacts::status($entry,$ordersOff,$domain),'by'=>self::requester($entry,$domain)];
    }
    /** The newest entries as Sheet::trace steps: the reason, then when and who.
     * $said is the page's status line, which names request $request: that request's reason is not said again. */
    public static function steps(Domain $domain, array $entries, ?int $request = null, ?string $said = null): array
    {
        $off=self::ordersOff($domain);
        return array_map(function(Operation|array $entry) use($domain,$off,$request,$said): array {
            $row=self::row($entry,$domain,$off);
            $sub=$row['sub']?StaffDomainStatus::sentence($row['sub']):null;
            if (self::repeatsHead($entry,$sub,$request,$said)) { $sub=null; }
            return Sheet::event($row['event'],$sub,$row['time']?->getTimestamp(),$row['by'],$row['status']['tone']??'gray',$row['status']['label']??null);
        },$entries);
    }
    /** Whether a row's reason sentence only repeats the head: $said is the page's status line, which names request $request.
     * Other requests, and every status, keep their words. */
    public static function repeatsHead(Operation|array $entry, ?string $sentence, ?int $request, ?string $said): bool
    { return $sentence!==null&&$said!==null&&$entry instanceof Operation&&(int)$entry->id===$request&&str_contains($said,$sentence); }
    /** A management request names who asked for it. Everything else runs on its own. */
    private static function requester(Operation $op, Domain $domain): string
    {
        try { $managed=isset($op->policy['management']); } catch (\Throwable) { return 'System'; }
        if (!$managed) { return 'System'; }
        if ((int)$op->authorized_by===(int)$domain->user_id) { return 'Customer'; }
        $id=(int)$op->authorized_by;
        return once(fn()=>trim((string)User::query()->whereKey($id)->get(['first_name','last_name'])->first()?->name)?:'Staff');
    }
    private static function at(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)||str_contains($value,"\0")) { return null; }
        try { return new \DateTimeImmutable($value); } catch (\Throwable) { return null; }
    }
}
