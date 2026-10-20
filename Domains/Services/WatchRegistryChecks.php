<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Dns\WatchRegistryTicket;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchRegistryObservation};
/** Claims the existing watch ledger; only registry evidence is appended here. */
final class WatchRegistryChecks
{
    public function __construct(private bool $enabled=false) {}
    public function enroll(int $id,int $owner,int $now): bool
    {
        if (!$this->enabled || DB::transactionLevel()!==0 || $now<0) { return false; }
        return DB::transaction(function()use($id,$owner,$now):bool {
            $t=(new WatchDnsChecks())->registryTarget($id,$owner);
            if (!$t) { return false; }
            DB::table('domains_watch_work')->insertOrIgnore(['watch_id'=>$id,'kind'=>'expiry','due_at'=>$now,'watch_revision'=>$t['watch']->revision]); return true;
        });
    }
    public function begin(int $id,int $owner,int $now): ?WatchRegistryTicket
    {
        if (!$this->enabled || DB::transactionLevel()!==0 || $now<0) { return null; }
        return DB::transaction(function()use($id,$owner,$now):?WatchRegistryTicket {
            $peek=DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','expiry')->first();
            if (!$peek) { return null; }
            $t=(new WatchDnsChecks())->registryTarget($id,$owner,true,(int)$peek->source_cursor);
            $q=DB::table('domains_watch_work')->where('id',$peek->id)->whereIn('watch_id',WatchName::select('id')->where('user_id',$owner)); $w=$q->lockForUpdate()->first();
            if (!$t && $w && !($w->lease_until>$now)) { $q->update(['due_at'=>$now+900,'safe_code'=>'target_changed']); }
            if (!$t || !$w || $w->due_at>$now || $w->backoff_until>$now || $w->lease_until>$now || $w->source_cursor!==$peek->source_cursor) { return null; }
            if ($w->claim!==null) { $q->update(PublicCheckRetry::abandoned($w,$id,'expiry',$now));return null; }
            $claim=bin2hex(random_bytes(32)); $q->update(['claim'=>$claim,'lease_until'=>$now+120,'watch_revision'=>$t['watch']->revision]);
            return new WatchRegistryTicket($id,$owner,$t['watch']->canonical_name,$t['stamp'],$claim,$now,(int)$w->source_cursor,$t['next_source_cursor']);
        });
    }
    public static function jitter(int $id,string $kind,int $bound): int
    { return (int)(hexdec(substr(hash('sha256',$id.'|'.$kind),0,8))%($bound+1)); }
    public static function urgent(?string $expiry,int $now): bool
    { return $expiry!==null && (new \DateTimeImmutable($expiry,new \DateTimeZone('UTC')))->getTimestamp()<=$now+30*86400; }
    public function finish(WatchRegistryTicket $t,array $r,string $revision,int $now,int $endpointUntil=0): bool
    {
        if (!$this->enabled || DB::transactionLevel()!==0 || !in_array($r['reason']??null,['facts','not_registered','no_expiry','no_coverage','endpoint_failed','deferred'],true)) { return false; }
        return DB::transaction(function()use($t,$r,$revision,$now,$endpointUntil):bool {
            $target=(new WatchDnsChecks())->registryTarget($t->watchId,$t->userId,true,$t->sourceCursor);
            $q=DB::table('domains_watch_work')->where('watch_id',$t->watchId)->where('kind','expiry'); $w=$q->lockForUpdate()->first();
            if (!$w || $w->claim!==$t->claim) { return false; }
            if (!$target || !hash_equals($t->stamp,$target['stamp'])) { $q->update(['claim'=>null,'lease_until'=>null,'due_at'=>max($now,$t->startedAt)+60,'safe_code'=>'target_changed']); return false; }
            if ($now<$t->startedAt || $now>=$t->startedAt+45 || $now>=(int)$w->lease_until) {
                $q->update($this->failure($t,(int)$w->attempts,$now,'deadline')); return false;
            }
            if ($r['reason']==='deferred') { $q->update(['claim'=>null,'lease_until'=>null,'due_at'=>max($now+60,$endpointUntil),'safe_code'=>$w->safe_code??'budget_exhausted']); return false; }
            $facts=$r['result']==='facts'; $urgent=$facts && self::urgent($r['expiry'],$now);
            $row=WatchRegistryObservation::create(['watch_id'=>$t->watchId,'user_id'=>$t->userId,'stamp'=>$t->stamp,'source_cursor'=>$t->sourceCursor,
                'bootstrap_revision'=>$revision,'started_at'=>$t->startedAt,'completed_at'=>$now,'valid_until'=>$facts?$t->startedAt+($urgent?43200:172800):null,
                'result'=>$r['result'],'reason'=>$r['reason'],'safe_code'=>$r['safe_code'],'facts'=>$r]);
            if ($r['reason']==='endpoint_failed') { $update=$this->failure($t,(int)$w->attempts,$now,'expiry_unknown'); $update['due_at']=$update['backoff_until']=max($update['due_at'],$endpointUntil); }
            else {
                $delay=$r['reason']==='no_coverage'?604800:($urgent?21600:86400+self::jitter($t->watchId,'expiry',3600));
                $update=['claim'=>null,'lease_until'=>null,'attempts'=>0,'backoff_until'=>null,'due_at'=>$now+$delay,'safe_code'=>$r['safe_code'],
                    'last_success_at'=>$facts?$t->startedAt:$w->last_success_at,'source_cursor'=>$t->nextSourceCursor];
            }
            $q->update($update); return $row->exists;
        });
    }
    private function failure(WatchRegistryTicket $t,int $attempts,int $now,string $code): array
    {
        $attempts=min(30,$attempts+1); $delay=[900,3600,21600,86400][min(3,$attempts-1)];
        $due=max($now,$t->startedAt)+$delay+self::jitter($t->watchId,'expiry|'.$attempts,intdiv($delay,10));
        return ['claim'=>null,'lease_until'=>null,'attempts'=>$attempts,'due_at'=>$due,'backoff_until'=>$due,'safe_code'=>$code];
    }
}
