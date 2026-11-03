<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Jobs;
use App\Models\{Notification,User};
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferScheduler,UpgradeGate};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeUnique};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Illuminate\Support\Facades\{Log,Route};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,PriceFill,PriceSync,StaffSettingsReview as Review,StaffSettingsWrites};
/** Manual price reads use the worker, just like scheduled reads, so the staff page stays usable. */
final class SyncPrices implements ShouldQueue,ShouldBeUnique
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $uniqueFor=900;
    public int $upgradeGeneration=0;
    // The bulk read has a 60-second deadline. Leave time to save before Paymenter's 90-second reservation expires.
    public const TIMEOUT=75;
    // Accepted prices allow a 25-second list read, a full batch deadline, and 30 seconds to save.
    public const ACCEPTED_TIMEOUT=25+20+5*PriceSync::BATCH_CALLS+30;
    public int $timeout=self::TIMEOUT;
    public int $tries=1;
    public bool $failOnTimeout=true;
    public const WORKER='Price sync needs a database or Redis queue with retry_after above %d seconds. Configure it and start the scheduler and queue worker, then try Sync again.';
    public function __construct(public int $actor,public int $server,public string $fingerprint,public ?string $reviewKey=null)
    {$this->upgradeGeneration=UpgradeGate::generation();$this->timeout=$reviewKey===null?self::TIMEOUT:self::ACCEPTED_TIMEOUT;}
    public function uniqueId(): string {return 'domains-price-sync:'.$this->server.':'.$this->actor.':'.($this->reviewKey===null?'list':'prices');}
    public static function isQueueRefusal(\Throwable $error): bool
    {return $error instanceof \DomainException&&in_array($error->getMessage(),[sprintf(self::WORKER,self::TIMEOUT),sprintf(self::WORKER,self::ACCEPTED_TIMEOUT)],true);}
    public static function enqueue(int $actor,int $server,?string $reviewKey=null): void
    {
        $connection=config('queue.connections.'.config('queue.default'),[]);
        $timeout=$reviewKey===null?self::TIMEOUT:self::ACCEPTED_TIMEOUT;
        // A reservation must outlive the job; otherwise two workers can send the same read.
        if(!in_array($connection['driver']??null,['database','redis'],true)||(int)($connection['retry_after']??0)<=$timeout){throw new \DomainException(sprintf(self::WORKER,$timeout));}
        $account=Account::where('server_id',$server)->sole();
        self::dispatch($actor,$server,CatalogueAcceptances::binding($account)->fingerprint,$reviewKey);
    }
    public function handle(): void
    {
        $user=User::find($this->actor);
        if(!Staff::allows($user,Staff::POLICY)||!Staff::allows($user,Review::permission('import'))){return;}
        try{
            if($this->upgradeGeneration!==UpgradeGate::generation()){throw new \DomainException('Settings changed. Reload this page.');}
            $account=Account::where('server_id',$this->server)->sole();
            if(!hash_equals($this->fingerprint,CatalogueAcceptances::binding($account)->fingerprint)){throw new \DomainException('Settings changed. Reload this page.');}
            if(($scheduler=OfferScheduler::routed())!==null){
                // Reviewed imports continue through StaffSettingsWrites, preserving their current ticket.
                if($this->reviewKey!==null){app(StaffSettingsWrites::class)->run(new Review($this->actor,$this->server,null,'import',$this->reviewKey,''),[]);}
                else{$scheduler->sync(CatalogueAcceptances::binding($account));}
                $this->notice('Price sync queued','The account worker will refresh prices. Check sync progress on What you sell.');return;
            }
            UpgradeGate::legacy($this->upgradeGeneration);
            if($this->reviewKey===null){
                $count=app(PriceFill::class)->run($account);
                $this->notice($count===0?'Another price read is running':'Prices updated',$count===0?'Its prices will appear when it finishes. Refresh What you sell.':'Saved '.$count.' TLDs, including retained rows without new prices. Refresh What you sell to see the prices.');
            }else{
                $result=app(StaffSettingsWrites::class)->run(new Review($this->actor,$this->server,null,'import',$this->reviewKey,''),[]);
                $this->notice('Prices synced',SettingsActions::syncSummary($result));
            }
        }catch(\Throwable $error){
            if(!$error instanceof \DomainException){Log::warning('Domains queued price sync failed.',['exception_class'=>$error::class]);}
            $this->notice("Couldn't sync prices",SettingsActions::refusal($error,'import',[],$this->server));
        }
    }
    public function failed(?\Throwable $error): void
    {$this->notice("Couldn't sync prices",'The background read stopped. Your last good prices are still saved. Check the queue worker, then try Sync again.');}
    private function notice(string $title,string $body): void
    {
        if(!Staff::allows(User::find($this->actor),Staff::VIEW)){return;}
        $route=$this->reviewKey===null?'filament.admin.pages.what-you-sell':'filament.admin.resources.domain-tlds.index';
        Notification::create(['user_id'=>$this->actor,'title'=>$title,'body'=>$body,'url'=>Route::has($route)?route($route,['account'=>$this->server]):null]);
    }
}
