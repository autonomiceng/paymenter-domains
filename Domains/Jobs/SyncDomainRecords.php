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
use Paymenter\Extensions\Servers\Domains\Services\{DomainObservation,ObservationRecords,ReadFailure};
/** Registrar reads can outlive a web request, so manual sync uses the existing queue worker. */
final class SyncDomainRecords implements ShouldQueue,ShouldBeUnique
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public const TIMEOUT=300;
    public const UNIQUE_FOR=900;
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
        return Cache::has($job->pendingKey());
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
        $job=new self($actor,(int)$d->id,$d->authority_generation,$d->account_fingerprint);
        $lock=app(UniqueLock::class);
        if (!$lock->acquire($job)) { return false; }
        // Dispatch directly after admission: PendingDispatch would try to acquire the same lock again.
        try { $job->recordHistory('Sync requested'); Cache::forget($job->uniqueId().':result'); Cache::put($job->pendingKey(),true,self::UNIQUE_FOR); app(Dispatcher::class)->dispatch($job); }
        catch (\Throwable $error) { Cache::forget($job->pendingKey()); $lock->release($job); throw $error; }
        return true;
    }
    public function handle(DomainObservation $observer): void
    {
        try {
            if (!Staff::allows(User::find($this->actor),'admin.domains.manage')) { return; }
            $d=Domain::find($this->domain);
            if (!$d||$d->status!=='active'||$d->authority_generation!==$this->generation||$d->account_fingerprint!==$this->fingerprint) { $this->notice('Sync stopped','Domain changed. Reload its page before trying Sync now again.'); return; }
            try {
                $result=$observer->run($this->domain,$this->generation,$this->fingerprint,readRecords:true);
                if ($result==='records_disabled') { $this->notice('Records not read','Record checks are paused or not set up for this registrar account. Ask your site admin to check Processing and record-read setup, then press Sync now again.'); return; }
                $failed=ObservationRecords::held($d->refresh())||in_array($result,ReadFailure::CODES,true)||$result==='read_deferred';
                if(!$failed&&$result==='refreshed'){\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::completedManually($d);}
                if ($result==='domain_not_in_account') { \Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::completedManually($d,$result); $this->notice("Couldn't read all records",'Domain is not in the registrar account. Check the assigned account or complete the transfer there, then use Sync now.'); return; }
                $this->notice($failed?"Couldn't read all records":'Domain synced',$failed?(ObservationRecords::limitNotice($d)??'Open the domain and check the sync notice, then try Sync now again.'):'The saved records are up to date.');
            } catch (\Throwable) { $this->notice("Couldn't sync domain",'Check the registrar account, then try Sync now again.'); }
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
            $p['sync_history']=array_slice([...$history,['event'=>$event,'actor_id'=>$this->actor,
                'at'=>app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->format(DATE_ATOM)]],-20);
            $d->update(['observation'=>$p]);

        });
    }
    private function notice(string $title,string $body): void
    {
        $this->recordHistory($title);
        Cache::put($this->uniqueId().':result',compact('title','body'),self::UNIQUE_FOR);
        if (!Staff::allows(User::find($this->actor))) { return; }
        $route='filament.admin.resources.domains.view';
        Notification::create(['user_id'=>$this->actor,'title'=>$title,'body'=>$body,'url'=>Route::has($route)?route($route,['record'=>$this->domain]):null]);
    }
}
