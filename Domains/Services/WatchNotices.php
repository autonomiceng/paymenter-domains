<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Models\{WatchNotice,WatchAudit};
/** Explicit internal composition, dormant by default. Never performs public or registrar I/O. */
final class WatchNotices
{
    public function __construct(private bool $enabled=false) {}
    public static function state(string $scope,bool $lock=false): array
    {
        $v=DB::table('domains_watch_notice_state')->where('scope',$scope)->when($lock,fn($q)=>$q->lockForUpdate())->value('data');
        return $v===null?[]:json_decode(Crypt::decryptString($v),true,64,JSON_THROW_ON_ERROR);
    }
    public static function write(string $scope,int $owner,array $state,int $now): void
    { DB::table('domains_watch_notice_state')->updateOrInsert(['scope'=>$scope],['user_id'=>$owner,'data'=>Crypt::encryptString(json_encode($state,JSON_THROW_ON_ERROR)),'updated_at'=>$now]); }
    public function plan(string $kind,int $id,int $owner,int $now): array
    {
        if (!$this->enabled || $now<0 || DB::transactionLevel()!==0) { return []; }
        return DB::transaction(function()use($kind,$id,$owner,$now):array {
            $t=WatchNoticeTarget::capture($kind,$id,$owner,$now);if (!$t) { return []; }
            $scope=WatchNoticePreferences::scope($owner,$t['name']);$state=self::state($scope,true);
            $changed=($state['target']??null)!==[$kind,$id];
            if ($changed) { $state['dns']=WatchNoticePlan::relinked($state['dns']??[]); }
            $state['target']=[$kind,$id];$plans=[];
            if (isset($state['dns']['episode'])) {
                $state['dns']['delivered']=DB::table('domains_watch_deliveries as d')->join('domains_watch_notices as n','n.id','=','d.notice_id')
                    ->where('n.scope',$scope)->where('n.kind','ns_changed')->where('d.state','accepted')->get(['d.channel','n.data'])
                    ->filter(fn($r)=>(json_decode(Crypt::decryptString($r->data),true)['episode']??null)===$state['dns']['episode'])->pluck('channel')->unique()->values()->all();
            }
            if ($kind==='watch') { $plans['expiry']=WatchNoticePlan::expiry($state['expiry']??[],$t['registry'],$now); }
            $plans['dns']=WatchNoticePlan::dns($state['dns']??[],$t['delegation'],$now,$t['expected']);
            foreach($plans as $axis=>$p) {
                $state[$axis]=$p['state'];$state['portal'][$axis]=$p['portal'];
                if (isset($p['resolve'])) {
                    if ($axis==='dns') { $state['dns']['resolved_episode']=$p['resolve']; }
                    WatchNotice::where('scope',$scope)->where('state','open')->whereIn('kind',$axis==='expiry'?['expiring','expired']:['ns_changed'])
                        ->get()->filter(fn($n)=>$n->data['episode']===$p['resolve'])->each(fn($n)=>$n->update(['state'=>'resolved','resolved_at'=>$now]));
                }
                $events=$p['notices'];
                // Same episode, new target and new evidence; old channel keys remain target-independent.
                if ($axis==='dns' && $kind==='managed' && isset($state['dns']['episode']) && $t['delegation'] && $t['delegation']['ns_confirmed'] && $t['delegation']['dns']==='external') {
                    $events[]=['kind'=>'ns_changed','episode'=>$state['dns']['episode'],'threshold'=>null,'date'=>gmdate('Y-m-d',$t['delegation']['at']),'observation_id'=>$t['delegation']['id'],'priority'=>1];
                }
                foreach($events as $event) { $this->record($scope,$t,$event,$now); }
                // Deduplicated local staff signal. Recovery rearms only the next outage episode.
                $bad=isset($p['health']) || in_array($axis.'_unavailable',$p['portal'],true);
                if ($bad && !($state['health'][$axis]??false)) {
                    WatchNoticeHealth::record($owner,$id,$kind,$p['health']??$axis.'_unavailable',$now);
                }$state['health'][$axis]=$bad;
            }
            if ($kind==='managed') {
                WatchNotice::where('scope',$scope)->whereIn('kind',['expiring','expired'])->where('state','open')->update(['state'=>'resolved','resolved_at'=>$now]);
                unset($state['portal']['expiry']);
            }
            self::write($scope,$owner,$state,$now);
            return WatchNotice::where('scope',$scope)->where('target_kind',$kind)->where($kind==='watch'?'watch_id':'managed_domain_id',$id)->where('state','open')->orderBy('id')->pluck('id')->all();
        });
    }
    private function record(string $scope,array $t,array $event,int $now): void
    {
        if ($t['kind']==='managed' && in_array($event['kind'],['expiring','expired'],true)) { throw new \LogicException('Managed expiry is not public notice authority.'); }
        $lineage=in_array($event['kind'],['expiring','expired'],true)?'':$t['lineage'];
        $episode=WatchNoticePlan::key($t['owner'],$t['name'],$lineage,$event['episode'],$event['kind'],$event['threshold']);
        $key=hash('sha256',$t['kind'].'|'.$t['id'].'|'.$episode);
        if (WatchNotice::where('notice_key',$key)->exists()) { return; }
        if (in_array($event['kind'],['expiring','expired'],true)) {
            WatchNotice::where('scope',$scope)->where('state','open')->whereIn('kind',['expiring','expired'])->where('threshold','>',$event['threshold'])
                ->get()->filter(fn($n)=>$n->data['episode']===$event['episode'])->each(fn($n)=>$n->update(['state'=>'resolved','resolved_at'=>$now]));
        }
        $family=$lineage===''?'registry':$t['family'];(new WatchMaintenance())->retain($family,$event['observation_id']);
        WatchNotice::create(['notice_key'=>$key,'scope'=>$scope,'target_kind'=>$t['kind'],'watch_id'=>$t['kind']==='watch'?$t['id']:null,
            'managed_domain_id'=>$t['kind']==='managed'?$t['id']:null,'user_id'=>$t['owner'],'kind'=>$event['kind'],'episode_key'=>$episode,'threshold'=>$event['threshold'],
            'family'=>$family,'observation_id'=>$event['observation_id'],'state'=>'open','first_seen_at'=>$now,'last_seen_at'=>$now,
            'data'=>['domain'=>$t['name'],'date'=>$event['date'],'episode'=>$event['episode'],'lineage'=>$lineage,'priority'=>$event['priority'],'channels'=>$event['channels']??['mail','app']]]);
    }
}
