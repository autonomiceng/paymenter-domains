<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Log};
/** Bounded retention and durable outage marker. Financial/audit/source history is never swept. */
final class WatchMaintenance
{
    private function alert(array $health): bool
    {
        Log::warning('Domain monitoring needs staff review.',['code'=>$health['reason']]);
        $title=$health['reason']==='checks_unsuccessful'?'Domain monitoring checks failed':'Domain monitoring stopped';
        $body=$health['reason']==='dispatch_overdue'?'Checks have not run since '.self::date($health['since']??null).'.':self::notice($health)['text'];
        return \Paymenter\Extensions\Servers\Domains\Admin\Support\StaffAlert::send($title,$body,'filament.admin.resources.domain-watch.index','admin.domains.watch.view','watch:'.$health['alert_key']);
    }
    private function families(int $now): array
    {
        $watch=DB::table('domains_watch_work as w')->whereExists(fn($q)=>$q->selectRaw('1')->from('domains_watch_names as n')->whereColumn('n.id','w.watch_id')->where('n.state','active')->whereNull('n.managed_domain_id'));
        $pending=static fn($q)=>$q->where(fn($w)=>$w->where('due_at','<=',$now)->orWhere('attempts','>',0)->orWhereNotNull('claim'))->exists();
        $managed=ManagedPublicReconciliation::eligible();
        $success=(int)DB::table('domains_watch_registry')->where('reason','<>','endpoint_failed')->max('completed_at');
        return ['registry'=>['pending'=>$pending((clone$watch)->where('kind','expiry')),'success'=>$success,'expected'=>$this->expectedDaily(clone$watch,'domains_watch_registry','watch_id','w',$now,true)],
            'watch_dns'=>['pending'=>$pending((clone$watch)->whereIn('kind',['delegation','points'])),'success'=>(int)DB::table('domains_watch_observations')->where('result','complete')->max('completed_at'),'expected'=>$this->expectedDaily(clone$watch,'domains_watch_observations','watch_id','w',$now)],
            'managed_dns'=>['pending'=>$pending(clone$managed),'success'=>(int)DB::table('domains_managed_public_observations')->where('result','complete')->max('completed_at'),'expected'=>$this->expectedDaily($managed,'domains_managed_public_observations','domain_id',ManagedPublicWorkSchema::TABLE,$now)]];
    }
    /** Healthy daily work gets its own expected completion plus two turns, including registry jitter. */
    private function expectedDaily($work,string $table,string $target,string $alias,int $now,bool $registry=false): int
    {
        return (int)$work->where($alias.'.kind',$registry?'expiry':'points')->where('attempts',0)
            ->where(fn($q)=>$q->whereNull('claim')->orWhere('lease_until','>',$now))
            ->whereExists(fn($q)=>$q->selectRaw('1')->from($table.' as o')->whereColumn('o.'.$target,$alias.'.'.$target)
                ->when($registry,fn($q)=>$q->where('o.reason','<>','endpoint_failed'),fn($q)=>$q->where('o.check_kind','points')->where('o.result','complete'))
                ->when($target==='domain_id',fn($q)=>$q->whereColumn('o.generation',$alias.'.generation')->whereColumn('o.user_id',$alias.'.user_id')->whereColumn('o.account_fingerprint',$alias.'.account_fingerprint'))
                ->whereRaw($alias.'.due_at BETWEEN o.completed_at + 86400 AND o.completed_at + '.($registry?90000:86400))
                ->whereNotExists(fn($n)=>$n->selectRaw('1')->from($table.' as newer')->whereColumn('newer.'.$target,'o.'.$target)
                    ->when(!$registry,fn($n)=>$n->where('newer.check_kind','points'))->whereColumn('newer.id','>','o.id')))->max('due_at');
    }
    public function health(int $now): array
    {
        $dispatch=DB::table('domains_watch_schedule')->where('scope','dispatch')->first();
        if (!$dispatch) { return ['outage'=>false,'reason'=>'waiting','since'=>null]; }
        $latest=max((int)DB::table('domains_watch_registry')->max('completed_at'),
            (int)DB::table('domains_watch_observations')->max('completed_at'),
            (int)DB::table('domains_managed_public_observations')->max('completed_at'));
        $progress=max((int)$dispatch->progress_at,$latest,(int)(json_decode($dispatch->data??'{}',true)['started_at']??0));
        if ($now>=$progress+600) { return ['outage'=>true,'reason'=>'dispatch_overdue','since'=>$progress?:null]; }
        $failed=[];
        foreach($this->families($now)as$family=>$state) {
            $since=(int)DB::table('domains_watch_schedule')->where('scope','health_'.$family)->value('cursor');
            if ($since===0) { continue; }
            $since=max($since,$state['success']);
            if ($state['pending'] && $since>0 && $now>=max($since+86400,($state['expected']??0)+600)) { $failed[$family]=$since; }
        }
        $local=json_decode($dispatch->data??'{}',true)['local_failures']??[];
        if ($local && $now>=min($local)+86400) { $failed['local']=min($local); }
        if ($failed) { return ['outage'=>true,'reason'=>'checks_unsuccessful','family'=>array_key_first($failed),'families'=>array_keys($failed),'since'=>min($failed)]; }
        return ['outage'=>false,'reason'=>'current','since'=>$progress?:null];
    }
    private static function date(?int $at): string{return $at?gmdate('M j, Y H:i',$at).' UTC':'the first run';}
    /** The Domain monitoring notice: one line, its tone and its one action (settings, failed_jobs or none). Normal states have none;
     * a short queue timeout shows under Registrars > account > System checks instead. */
    public static function notice(array $health): ?array
    {
        $since=self::date($health['since']??null);
        if ($health['reason']==='checks_unsuccessful') {
            $families=$health['families']??[$health['family']??'local'];
            $names=array_values(array_unique(array_map(static fn($f)=>['registry'=>'Expiry checks','watch_dns'=>'DNS checks','managed_dns'=>'DNS checks'][$f]??'Domain discovery',$families)));
            $action=array_intersect(['watch_dns','managed_dns'],$families)?'settings':(in_array('local',$families,true)?'failed_jobs':null);
            return ['text'=>implode(' and ',$names).(count($names)>1||$names[0]!=='Domain discovery'?' have':' has').' failed since '.$since.'.','tone'=>'danger','action'=>$action];
        }
        return match($health['reason']) {
            'disabled'=>['text'=>'Domain monitoring is off on this site.','tone'=>'warning','action'=>null],
            'dispatch_overdue'=>['text'=>'Monitoring checks have not run since '.$since.'.','tone'=>'danger','action'=>'failed_jobs'],
            default=>null,
        };
    }
    public function tick(int $now): void
    {
        foreach($this->families($now)as$family=>$state) {
            $q=DB::table('domains_watch_schedule')->where('scope','health_'.$family);
            if (!$state['pending']) { $q->delete(); }
            else {
                DB::table('domains_watch_schedule')->insertOrIgnore(['scope'=>'health_'.$family,'cursor'=>$now]);
                $q->where('cursor','<',$state['success'])->update(['cursor'=>$state['success']]);
            }
        }
        $health=$this->health($now);$q=DB::table('domains_watch_schedule')->where('scope','dispatch');
        $pending=DB::transaction(function()use($q,$now,$health):?array {
            $row=(clone$q)->lockForUpdate()->first();if(!$row){return null;}
            $outage=DB::table('domains_watch_schedule')->where('scope','outage');
            $prior=json_decode((clone$outage)->value('data')??'{}',true);
            $episode=($health['reason']??'').'|'.implode(',',$health['families']??[]);
            $previous=($prior['reason']??'').'|'.implode(',',$prior['families']??[]);
            if ($health['outage'] && ($row->alert_at===null || $episode!==$previous)) {
                $q->update(['alert_at'=>$now]);
                $prior=[...$health,'alert_pending'=>true,'alert_retry_at'=>0,'alert_key'=>bin2hex(random_bytes(16))];
                DB::table('domains_watch_schedule')->updateOrInsert(['scope'=>'outage'],['data'=>json_encode($prior),'progress_at'=>$now]);
            } elseif (!$health['outage']) {
                if($row->alert_at!==null){$q->update(['alert_at'=>null]);}
                if($prior['alert_pending']??false){$prior['alert_pending']=false;$outage->update(['data'=>json_encode($prior)]);}
                return null;
            }
            if(!($prior['alert_pending']??false)||($prior['alert_retry_at']??0)>$now){return null;}
            // Commit a bounded delivery lease with the episode; crashed delivery retries on a later turn.
            $prior['alert_retry_at']=$now+60;$prior['alert_attempt']=bin2hex(random_bytes(16));
            $outage->update(['data'=>json_encode($prior)]);
            return $prior;
        });
        if($pending!==null){
            try{
                if($this->alert($pending)){
                    $encoded=json_encode($pending);$pending['alert_pending']=false;
                    DB::table('domains_watch_schedule')->where('scope','outage')->where('data',$encoded)->update(['data'=>json_encode($pending)]);
                }
            }catch(\Throwable){Log::warning('Domain monitoring alert will be retried.');}
        }
        foreach(['registry'=>['domains_watch_registry','watch_id','facts'],'watch_dns'=>['domains_watch_observations','watch_id','complete'],
            'managed_dns'=>['domains_managed_public_observations','domain_id','complete']]as$family=>[$table,$target,$success]) {
            $old=DB::table($table.' as o')->where('o.completed_at','<',$now-90*86400)
                ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('domains_public_evidence_refs as r')->where('r.family',$family)->whereColumn('r.observation_id','o.id'))
                ->where(function($q)use($table,$target,$success,$family) {
                    if ($family==='registry') {
                        $q->where('o.result','<>',$success)->orWhereExists(fn($n)=>$n->selectRaw('1')->from($table.' as newer')->whereColumn('newer.'.$target,'o.'.$target)->whereColumn('newer.user_id','o.user_id')->where('newer.result',$success)->whereColumn('newer.id','>','o.id'));
                        return;
                    }
                    foreach(['dns','website','email']as$axis) {
                        $q->where(fn($a)=>$a->whereNull('o.'.$axis)->orWhereExists(fn($n)=>$n->selectRaw('1')->from($table.' as newer')->whereColumn('newer.'.$target,'o.'.$target)->whereColumn('newer.user_id','o.user_id')->whereColumn('newer.check_kind','o.check_kind')->whereNotNull('newer.'.$axis)->whereColumn('newer.id','>','o.id')));
                    }
                })->orderBy('o.id')->limit(100)->pluck('o.id');
            foreach($old as$id) {
                DB::transaction(function()use($table,$family,$id):void {
                    if (!DB::table($table)->where('id',$id)->lockForUpdate()->exists()) { return; }
                    if (!DB::table('domains_public_evidence_refs')->where('family',$family)->where('observation_id',$id)->lockForUpdate()->exists()) { DB::table($table)->where('id',$id)->delete(); }
                });
            }
        }
    }
    /** The notice producer pins references before delivery; retained reference rows are append-only. */
    public function retain(string $family,int $id): void
    {
        if (DB::transactionLevel()<1 || !in_array($family,['registry','watch_dns','managed_dns'],true) || $id<1) { throw new \DomainException('Invalid public evidence reference.'); }
        $table=['registry'=>'domains_watch_registry','watch_dns'=>'domains_watch_observations','managed_dns'=>'domains_managed_public_observations'][$family];
        if (!DB::table($table)->where('id',$id)->lockForUpdate()->exists()) { throw new \DomainException('Public evidence is unavailable.'); }
        DB::table('domains_public_evidence_refs')->insertOrIgnore(['family'=>$family,'observation_id'=>$id]);
    }
}
