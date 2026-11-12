<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Jobs;
use App\Models\{Notification,User};
use Illuminate\Bus\{Queueable,UniqueLock};
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeUnique};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Illuminate\Support\Facades\{Cache,DB,Route};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{DomainObservation,HoldingObservationWork,HoldingReadRetry,ObservationRecords,ReadDeferred,ReadFailure};
/** Registrar reads can outlive a web request, so manual sync uses the existing queue worker. */
final class SyncDomainRecords implements ShouldQueue,ShouldBeUnique
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public const TIMEOUT=300;
    public const UNIQUE_FOR=900;
    public const RUNNING='A scheduled registrar read is running for this domain. This page updates when it finishes.';
    public const WORKER='Sync now needs a database or Redis queue with retry_after above 300 seconds. Ask your site admin to check the queue worker, then try again.';
    public int $timeout=self::TIMEOUT;
    public int $uniqueFor=self::UNIQUE_FOR;
    public int $tries=1;
    public bool $failOnTimeout=true;
    public function __construct(public int $actor,public int $domain,public int $generation,public string $fingerprint) {}
    public function uniqueId(): string { return 'domains-record-sync:'.$this->domain.':'.$this->generation.':'.$this->fingerprint; }
    private function pendingKey(): string { return $this->uniqueId().':pending'; }
    /** Bounded UI signal; no provider read or lock acquisition while rendering the page. */
    public static function pending(Domain $d): bool
    {
        $job=new self(0,(int)$d->id,$d->authority_generation,$d->account_fingerprint);
        return Cache::has($job->pendingKey())||HoldingObservationWork::leased($d,app(Clock::class)->now());
    }
    /** Last bounded completion notice, also shown beside the facts it did or did not refresh. */
    public static function result(Domain $d): ?array
    {
        $job=new self(0,(int)$d->id,$d->authority_generation,$d->account_fingerprint);
        return Cache::get($job->uniqueId().':result');
    }
    public static function enqueue(int $actor,Domain $d): bool
    {
        $connection=config('queue.connections.'.config('queue.default'),[]);
        // A reservation must outlive the read; sync queues would still block the staff page.
        if (!in_array($connection['driver']??null,['database','redis'],true)||(int)($connection['retry_after']??0)<=self::TIMEOUT) { throw new \DomainException(self::WORKER); }
        $cache=config('cache.stores.'.config('cache.default').'.driver');
        if (!in_array($cache,['redis','database','memcached'],true)) { throw new \DomainException('Sync now needs a shared cache to prevent duplicate reads. Ask your site admin to configure Redis, database or Memcached cache, then try again.'); }
        if ($d->status!=='active') { throw new \DomainException('Domain is no longer active. Reload this page.'); }
        // The worker also yields to a live lease; this refusal only explains it before queueing.
        if (HoldingObservationWork::leased($d,app(Clock::class)->now())) { throw new \DomainException(self::RUNNING); }
        $job=new self($actor,(int)$d->id,$d->authority_generation,$d->account_fingerprint);
        $lock=app(UniqueLock::class);
        if (!$lock->acquire($job)) { return false; }
        // Dispatch directly after admission: PendingDispatch would try to acquire the same lock again.
        try { $job->tryHistory('Sync requested'); Cache::forget($job->uniqueId().':result'); Cache::put($job->pendingKey(),true,self::UNIQUE_FOR); app(Dispatcher::class)->dispatch($job); }
        catch (\Throwable $error) { Cache::forget($job->pendingKey()); $lock->release($job); throw $error; }
        return true;
    }
    public function handle(DomainObservation $observer): void
    {
        try {
            if (!Staff::allows(User::find($this->actor),'admin.domains.manage')) { $this->notice('Sync stopped','The staff permission to sync this domain was removed.'); return; }
            $d=Domain::find($this->domain);
            if (!$d||$d->status!=='active'||$d->authority_generation!==$this->generation||$d->account_fingerprint!==$this->fingerprint) { $this->notice('Sync stopped','Domain changed. Reload its page before trying Sync now again.'); return; }
            $clock=app(Clock::class); $work=0; $claim=''; $lost=false;
            try {
                // Take the scheduler's lease so a scheduled read and this one never overlap; never take a live one.
                $lease=HoldingObservationWork::claimManual($d,$clock->now(),self::TIMEOUT);
                if ($lease===null) { $this->notice('Sync not started','A scheduled registrar read started first. Its result shows on this page.'); return; }
                [$work,$claim]=$lease;
                $current=function()use($work,$claim,$clock,&$lost):void {
                    if (!HoldingObservationWork::ownsManual($work,$claim,$clock->now())) { $lost=true; throw new ReadDeferred('Manual read lease lost.'); }
                };
                $before=self::recordFacts($d);
                $result=$observer->run($this->domain,$this->generation,$this->fingerprint,readRecords:true,current:$current);
                if ($result==='records_disabled') { $this->notice('Records not read','Record checks are paused or not set up for this registrar account. Ask your site admin to check Processing and record-read setup, then press Sync now again.'); return; }
                $failed=ObservationRecords::held($d->refresh())||in_array($result,ReadFailure::CODES,true)||$result==='read_deferred';
                if(!$failed&&$result==='refreshed'){HoldingReadRetry::completedManually($d,claim:$claim);}
                if ($result==='domain_not_in_account') { HoldingReadRetry::completedManually($d,$result,$claim); $this->notice("Couldn't read all records",'Domain is not in the registrar account. Check the assigned account or complete the transfer there, then use Sync now.'); return; }
                if (!$failed&&$result!=='refreshed') { $this->notice('Sync needs a check','The registrar read finished, but its facts differ from the saved domain. Check the domain and its open request.'); return; }
                if ($failed) { $this->notice("Couldn't read all records",ObservationRecords::limitNotice($d)??'Open the domain and check the sync notice, then try Sync now again.'); }
                else {
                    $after=self::recordFacts($d);
                    $changed=(bool)array_filter(array_keys($before),fn($key)=>array_key_exists($key,$after)&&$before[$key]!==$after[$key]);
                    $this->notice('Domain synced','The saved records are up to date.',bell:false);
                    if ($changed) { $this->bell('Domain records changed','The saved registrar records changed. Open the domain to review them.'); }
                }
            } catch (\Throwable) {
                // After its lease expires, the scheduler may claim the work, so this read must not save its result.
                if ($lost) { $this->notice('Sync stopped','The registrar read ran past its time limit. Press Sync now again.'); return; }
                $this->notice("Couldn't sync domain",'Check the registrar account, then try Sync now again.');
            } finally { HoldingObservationWork::releaseManual($work,$claim); }
        } finally { Cache::forget($this->pendingKey()); }
    }
    public function failed(?\Throwable $error): void
    { Cache::forget($this->pendingKey()); $this->notice("Couldn't sync domain",'The background read stopped. Ask your site admin to check the queue worker, then try Sync now again.'); }
    private function recordHistory(string $event): void
    {
        // Only this domain row is locked; no provider or financial work runs inside this transaction.
        DB::transaction(function()use($event):void {
            $d=Domain::whereKey($this->domain)->lockForUpdate()->first();
            if (!$d||$d->authority_generation!==$this->generation||$d->account_fingerprint!==$this->fingerprint) { return; }
            $p=$d->observation??[];$history=$p['sync_history']??[];
            $actor=User::find($this->actor);
            $p['sync_history']=array_slice([...$history,['event'=>$event,'actor_id'=>$this->actor,
                'actor_name'=>$actor?(trim($actor->first_name.' '.$actor->last_name)?:'Staff #'.$actor->id):null,
                'at'=>app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->format(DATE_ATOM)]],-20);
            $d->update(['observation'=>$p]);
        });
    }
    /** Activity history is secondary: an unreadable saved observation must not hide the sync result or block queueing. */
    private function tryHistory(string $event): void
    {
        try { $this->recordHistory($event); }
        catch (\Throwable $error) { report($error); }
    }
    /** Compare confirmed values only. Read times, history and newly confirmed families do not need a bell notice. */
    private static function recordFacts(Domain $d): array
    {
        $record=ObservationRecords::current($d); $facts=[];
        foreach (['families'=>'records','settings'=>'facts'] as $group=>$field) {
            foreach ($record[$group]??[] as $kind=>$family) {
                if (($family['state']??null)==='complete') { $facts[$group.'.'.$kind]=self::ordered($family[$field]??[]); }
            }
        }
        return $facts;
    }
    private static function ordered(array $values): array
    {
        $list=array_is_list($values);
        foreach ($values as &$value) { if (is_array($value)) { $value=self::ordered($value); } } unset($value);
        if ($list) { usort($values,fn($a,$b)=>serialize($a)<=>serialize($b)); } else { ksort($values); }
        return $values;
    }
    private function notice(string $title,string $body,bool $bell=true): void
    {
        Cache::put($this->uniqueId().':result',compact('title','body'),self::UNIQUE_FOR);
        $this->tryHistory($title);
        if ($bell) { $this->bell($title,$body); }
    }
    private function bell(string $title,string $body): void
    {
        if (!Staff::allows(User::find($this->actor))) { return; }
        $route='filament.admin.resources.domains.view';
        // Several syncs can finish in a row, so each bell item names its domain. The page compares the cached title, so the title stays as is.
        $name=Domain::whereKey($this->domain)->value('domain');
        Notification::create(['user_id'=>$this->actor,'title'=>$title,'body'=>is_string($name)&&$name!==''?$name.': '.$body:$body,'url'=>Route::has($route)?route($route,['record'=>$this->domain]):null]);
    }
}
