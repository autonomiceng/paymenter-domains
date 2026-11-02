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
use Illuminate\Support\Facades\Route;
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
        try { app(Dispatcher::class)->dispatch($job); }
        catch (\Throwable $error) { $lock->release($job); throw $error; }
        return true;
    }
    public function handle(DomainObservation $observer): void
    {
        if (!Staff::allows(User::find($this->actor),'admin.domains.manage')) { return; }
        $d=Domain::find($this->domain);
        if (!$d||$d->status!=='active'||$d->authority_generation!==$this->generation||$d->account_fingerprint!==$this->fingerprint) { $this->notice('Sync stopped','Domain changed. Reload its page before trying Sync now again.'); return; }
        try {
            $result=$observer->run($this->domain,$this->generation,$this->fingerprint,readRecords:true);
            if ($result==='records_disabled') { $this->notice('Records not read','Record checks are paused or not set up for this registrar account. Ask your site admin to check Processing and record-read setup, then press Sync now again.'); return; }
            $failed=ObservationRecords::held($d->refresh())||in_array($result,ReadFailure::CODES,true)||$result==='read_deferred';
            $this->notice($failed?"Couldn't read all records":'Domain synced',$failed?'Open the domain and check the sync notice, then try Sync now again.':'Refresh the domain page to see the saved records.');
        } catch (\Throwable) { $this->notice("Couldn't sync domain",'Check the registrar account, then try Sync now again.'); }
    }
    public function failed(?\Throwable $error): void
    { $this->notice("Couldn't sync domain",'The background read stopped. Ask your site admin to check the queue worker, then try Sync now again.'); }
    private function notice(string $title,string $body): void
    {
        if (!Staff::allows(User::find($this->actor))) { return; }
        $route='filament.admin.resources.domains.view';
        Notification::create(['user_id'=>$this->actor,'title'=>$title,'body'=>$body,'url'=>Route::has($route)?route($route,['record'=>$this->domain]):null]);
    }
}
