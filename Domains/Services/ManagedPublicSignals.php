<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{PublicSignalsTarget,ManagedDnsTicket,DnsCollector,DnsContext,DnsReading,QuotaDnsReader};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,ManagedPublicObservation};
/** Explicit internal composition, dormant by default. Public DNS never mutates holding authority. */
final class ManagedPublicSignals
{
    private \Closure $clock;
    public function __construct(private DnsReader $reader,private bool $enabled=false,?callable $clock=null,
        private ?int $defaultProfile=null,private ?\Closure $hint=null)
    { $this->clock=$clock===null?static fn():int=>time():\Closure::fromCallable($clock); }
    public function enabled(): bool { return $this->enabled; }
    public function enqueue(int $id,int $owner,?int $now=null): bool
    {
        $now??=($this->clock)();
        if (!$this->enabled || $now<0 || DB::transactionLevel()!==0) { return false; }
        if (!Domain::whereKey($id)->where('user_id',$owner)->exists()) { return false; }
        try { $changed=(new ManagedPublicEnrollment())->reconcile($id,$owner,$now,$this->defaultProfile); }
        catch (ManagedPublicIdentityChanged) { $changed=null; }
        catch (\LogicException|\RuntimeException) { return false; }
        if ($changed===null) { $this->retire($id,$now);return false; }
        // This runs after the relink transaction commits. The work rows, not the hint, are durable intent.
        try { if ($changed && $this->hint) { ($this->hint)($id,$owner); } } catch (\Throwable) { /* Daily sweep repairs lost hints. */ }
        return true;
    }
    public function retire(int $id,int $now): void
    {
        if (!$this->enabled || DB::transactionLevel()!==0) { return; }
        DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->whereNull('retired_at')
            ->update(['retired_at'=>$now,'claim'=>null,'lease_until'=>null,'safe_code'=>'target_changed']);
    }
    public function begin(int $id,int $owner,string $kind,int $now): ?ManagedDnsTicket
    {
        if (!$this->enabled || $now<0 || DB::transactionLevel()!==0 || !in_array($kind,['delegation','points'],true)) { return null; }
        return DB::transaction(function()use($id,$owner,$kind,$now):?ManagedDnsTicket {
            $seed=Domain::whereKey($id)->where('user_id',$owner)->first();if (!$seed) { return null; }
            $q=DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->where('generation',$seed->authority_generation)->where('kind',$kind);
            $peek=$q->first();if (!$peek) { return null; }
            $t=(new ManagedPublicTarget())->capture($id,$owner,$now,(int)$peek->source_cursor);
            if (!$t) { return null; }$d=$t['domain'];$c=$t['config'];$p=$t['profile'];$w=$q->lockForUpdate()->first();
            if (!$w || $w->retired_at!==null || $w->safe_code==='awaiting_acceptance' || (int)$w->user_id!==$owner
                || $w->account_fingerprint!==$d->account_fingerprint || $w->due_at>$now || ($w->backoff_until??0)>$now
                || ($w->lease_until??0)>$now || (int)$w->source_cursor!==(int)$peek->source_cursor) { return null; }
            if ($w->claim!==null) { $q->update(PublicCheckRetry::abandoned($w,$id,$kind,$now));return null; }
            $claim=bin2hex(random_bytes(32));$q->update(['claim'=>$claim,'lease_until'=>$now+120,'target_stamp'=>$t['stamp'],
                'domain_revision'=>$d->confirmed_revision,'config_revision'=>$c->revision,'profile_id'=>$p?->id,'profile_revision'=>$p?->revision]);
            $points=ManagedPublicObservation::where('domain_id',$id)->where('check_kind','points')->where('source_digest',$t['stamp'])
                ->where('result','complete')->where('valid_until','>',$now)->where('started_at','<=',$now)->orderByDesc('id')->first();
            $interval=$kind==='points'?86400:($c->expected_dns==='platform' && $points?->website===false && $points?->email===false?3600:21600);
            return new ManagedDnsTicket(PublicSignalsTarget::managed($id,$owner,$d->account_fingerprint,$d->authority_generation,$d->confirmed_revision),
                $kind,$claim,$now,$t['stamp'],$p?(int)$p->id:null,$p?->revision,$c->revision,$p?->profile(),$t['hosts'],(int)$w->cursor,$interval,
                (int)$w->source_cursor,$t['next_source_cursor'],$t['overflow']);
        });
    }
    public function observe(int $id,int $owner,string $kind): bool
    {
        $now=($this->clock)();
        if (!$this->enabled || $now<0 || DB::transactionLevel()!==0 || !in_array($kind,['delegation','points'],true)) { return false; }
        if (!$this->enqueue($id,$owner,$now)) { $this->unavailable($id,$owner,$kind,$now);return false; }
        try { $ticket=$this->begin($id,$owner,$kind,$now); } catch (\LogicException|\RuntimeException) { $ticket=null; }
        if (!$ticket) { $this->unavailable($id,$owner,$kind,$now);return false; }
        if (!$ticket->profile) { return $this->finish($ticket,new DnsReading($kind),($this->clock)()); }
        $quota=new PublicDnsQuota();$token=$quota->acquire(($this->clock)());
        if ($token===null) { $this->defer($ticket,($this->clock)());return false; }
        try {
            $reader=new QuotaDnsReader($this->reader,$quota,$token,$this->clock);
            [$reading,$cursor,$sourceCursor]=(new DnsCollector($reader))->collect($ticket,new DnsContext($this->clock));
            return $this->finish($ticket,$reading,($this->clock)(),$cursor,$sourceCursor,$reader->saturated);
        } finally { $quota->release($token); }
    }
    public function defer(ManagedDnsTicket $t,int $now): void
    {
        DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$t->target->id)->where('generation',$t->target->generation)
            ->where('kind',$t->kind)->where('claim',$t->claim)->update(['claim'=>null,'lease_until'=>null,'due_at'=>max($now,$t->startedAt)+60,'safe_code'=>DB::raw("CASE WHEN safe_code IN ('ready','rotation') OR safe_code IS NULL THEN 'budget_exhausted' ELSE safe_code END")]);
    }
    public function finish(ManagedDnsTicket $ticket,DnsReading $reading,int $now,int $cursor=0,?int $sourceCursor=null,bool $saturated=false): bool
    {
        if (!$this->enabled || DB::transactionLevel()!==0 || $ticket->kind!==$reading->kind || $cursor<0
            || ($sourceCursor!==null && !in_array($sourceCursor,[$ticket->sourceCursor,$ticket->nextSourceCursor],true))) { return false; }
        return DB::transaction(function()use($ticket,$reading,$now,$cursor,$sourceCursor,$saturated):bool {
            $id=$ticket->target->id;$t=(new ManagedPublicTarget())->capture($id,$ticket->target->owner,$now,$ticket->sourceCursor);
            $q=DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->where('generation',$ticket->target->generation)->where('kind',$ticket->kind);
            $w=$q->lockForUpdate()->first();if (!$w || $w->claim!==$ticket->claim) { return false; }
            if (!$t || !hash_equals($ticket->stamp,$t['stamp']) || (int)$w->source_cursor!==$ticket->sourceCursor) {
                $q->update(['claim'=>null,'lease_until'=>null,'due_at'=>max($now,$ticket->startedAt)+60,'safe_code'=>'target_changed']);return false;
            }
            if ($now<$ticket->startedAt || $now>=$ticket->startedAt+45 || $now>=(int)$w->lease_until) {
                $q->update($this->failure($w,$id,$ticket->kind,$ticket->startedAt,$now,$now<$ticket->startedAt?'clock_reversal':'deadline'));return false;
            }
            if ($ticket->sourceOverflow) { $reading=$reading->withOmittedHosts(); }
            $complete=$reading->result()==='complete';$code=$ticket->profile===null?'profile_unavailable':$reading->safeCode();
            $confirmed=false;$earlyPoints=false;$delay=$ticket->interval;
            if ($complete && $ticket->kind==='delegation') { [$confirmed,$delay,$earlyPoints]=$this->confirmation($ticket,$reading,$now); }
            ManagedPublicObservation::create(['domain_id'=>$id,'user_id'=>$ticket->target->owner,'account_fingerprint'=>$ticket->target->account,
                'generation'=>$ticket->target->generation,'domain_revision'=>$ticket->target->revision,'config_revision'=>$ticket->configRevision,
                'profile_id'=>$ticket->profileId,'profile_revision'=>$ticket->profileRevision,'source_digest'=>$ticket->stamp,'source_cursor'=>$ticket->sourceCursor,
                'check_kind'=>$ticket->kind,'started_at'=>$ticket->startedAt,'completed_at'=>$now,'valid_until'=>$reading->result()==='unknown'?null:$ticket->startedAt+2*$ticket->interval,
                'result'=>$reading->result(),'dns'=>$reading->dns,'website'=>$reading->website,'email'=>$reading->email,'mixed'=>$reading->mixed,'partial'=>!$complete,
                'ns_confirmed'=>$confirmed,'safe_code'=>$code,'facts'=>$reading->facts(),'digest'=>$reading->digest()]);
            $budget=$saturated || in_array($code,['budget_exhausted','rotation'],true);
            $values=$complete?['attempts'=>0,'due_at'=>$now+$delay,'backoff_until'=>null]:($budget?
                ['attempts'=>$w->attempts,'due_at'=>$now+($saturated?60:($code==='rotation'?$ticket->interval:60)),'backoff_until'=>null]:$this->failure($w,$id,$ticket->kind,$ticket->startedAt,$now,$code));
            $q->update([...$values,'claim'=>null,'lease_until'=>null,'safe_code'=>$code??'ready','last_success_at'=>$complete?$ticket->startedAt:$w->last_success_at,
                'cursor'=>$cursor,'source_cursor'=>$sourceCursor??$ticket->sourceCursor]);
            if ($earlyPoints) { DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->where('generation',$ticket->target->generation)->where('kind','points')
                ->where(fn($q)=>$q->whereNull('lease_until')->orWhere('lease_until','<=',$now))->where('due_at','>',$now)->update(['due_at'=>$now]); }
            return true;
        });
    }
    /** Failed authority capture must yield the owner's turn without retiring transient failures. */
    private function unavailable(int $id,int $owner,string $kind,int $now): void
    {
        DB::transaction(function()use($id,$owner,$kind,$now):void {
            $d=Domain::whereKey($id)->where('user_id',$owner)->first();if (!$d) { return; }
            $q=DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->where('user_id',$owner)
                ->where('account_fingerprint',$d->account_fingerprint)->where('generation',$d->authority_generation)->where('kind',$kind)
                ->whereNull('retired_at')->where(fn($q)=>$q->whereNull('safe_code')->orWhere('safe_code','<>','awaiting_acceptance'))->where('due_at','<=',$now)
                ->where(fn($q)=>$q->whereNull('lease_until')->orWhere('lease_until','<=',$now))
                ->where(fn($q)=>$q->whereNull('backoff_until')->orWhere('backoff_until','<=',$now));
            $w=$q->lockForUpdate()->first();if (!$w) { return; }
            $q->update($this->failure($w,$id,$kind,$now,$now,'authority_unavailable'));
        });
    }
    private function failure(object $w,int $id,string $kind,int $startedAt,int $now,?string $code): array
    {
        $attempts=min(30,(int)$w->attempts+1);$delay=[900,3600,21600,86400][min(3,$attempts-1)];
        $jitter=(int)(hexdec(substr(hash('sha256',$id.'|'.$kind.'|'.$attempts),0,6))%(intdiv($delay,10)+1));$due=max($now,$startedAt)+$delay+$jitter;
        return ['claim'=>null,'lease_until'=>null,'attempts'=>$attempts,'due_at'=>$due,'backoff_until'=>$due,'safe_code'=>$code];
    }
    private function confirmation(ManagedDnsTicket $t,DnsReading $reading,int $now): array
    {
        $prior=ManagedPublicObservation::where('domain_id',$t->target->id)->where('check_kind','delegation')->where('source_digest',$t->stamp)
            ->where('result','complete')->orderByDesc('id')->first();
        $ttls=[];foreach($prior?->facts['queries']??[]as$q){foreach($q['records']as$r){$ttls[]=$r['ttl'];}}
        $wait=min(21600,max(900,...($ttls?:[0])));
        $same=$prior && $prior->dns===$reading->dns;
        $fresh=$prior && $now>=$prior->started_at && $now<$prior->valid_until;
        // A candidate may wait longer than badge freshness for its TTL; expired confirmation never carries forward.
        $candidate=$prior && !$prior->ns_confirmed && $now>=$prior->started_at
            && $now<=$prior->started_at+max(2*$t->interval,$wait+$t->interval);
        $confirmed=$same && (($prior->ns_confirmed && $fresh) || ($candidate && $t->startedAt>=$prior->started_at+$wait));
        $ttls=[];foreach($reading->queries as$q){foreach($q['records']as$r){$ttls[]=$r['ttl'];}}
        $nextWait=min(21600,max(900,...($ttls?:[0])));
        return [$confirmed,$confirmed?$t->interval:($same && $candidate?max(1,$prior->started_at+$wait-$now):$nextWait),$confirmed && !$prior->ns_confirmed];
    }
}
