<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Jobs\RunPublicCheck;
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
/** Separate, default-off dispatcher on the sole extension tick. No financial authority or native cron listener. */
final class WatchWork
{
    private \Closure $clock; private \Closure $dispatch;
    public function __construct(private DnsReader $reader,private WatchDiscoveryWork $discovery,private bool $enabled=false,
        ?callable $clock=null,?callable $dispatch=null,private ?int $managedProfile=null)
    {
        $this->clock=$clock===null?static fn():int=>time():\Closure::fromCallable($clock);
        $this->dispatch=$dispatch===null?static fn($target)=>RunPublicCheck::dispatch($target):\Closure::fromCallable($dispatch);
    }
    public function active(): bool { return $this->enabled && $this->discovery->ready(); }
    public function queueReady(): bool
    {
        $c=config('queue.connections.'.config('queue.default'),[]);
        return !in_array($c['driver']??'sync',['sync','null'],true) && (int)($c['retry_after']??0)>=180;
    }
    private function signals(): ManagedPublicSignals { return new ManagedPublicSignals($this->reader,$this->active(),$this->clock,$this->managedProfile); }
    public function tick(): int
    {
        if (!$this->active() || !$this->queueReady() || DB::transactionLevel()!==0 || !Schema::hasTable('domains_watch_schedule')) { return 0; }
        $now=($this->clock)();
        $run=DB::transaction(function()use($now):?array {
            DB::table('domains_watch_schedule')->insertOrIgnore(['scope'=>'dispatch']);
            $q=DB::table('domains_watch_schedule')->where('scope','dispatch'); $row=$q->lockForUpdate()->first();
            if ($now<0 || $row->due_at>$now) { return null; }
            $q->update(['due_at'=>$now+300,'data'=>$row->data??json_encode(['started_at'=>$now]),'cursor'=>(int)$row->cursor+1]); return ['turn'=>(int)$row->cursor+1,'data'=>json_decode($row->data??'{}',true)];
        });
        if (!$run) { return 0; }
        $failures=$run['data']['local_failures']??[];$discovered=0;
        $this->stage(fn()=>$this->products());
        $ok=$this->stage(function()use($now,&$discovered):void {$discovered=$this->discovery->tick($now);});
        if (!$ok || $this->discovery->failed) { $failures['discovery']??=$now; }
        elseif ($discovered>0) { unset($failures['discovery']); }
        $reconcile=new ManagedPublicReconciliation($this->signals());
        foreach(['managed_enrollment'=>fn()=>$reconcile->sweep($now),'watch_enrollment'=>fn()=>$this->enroll($now)]as$key=>$stage) {
            $processed=0;$ok=$this->stage(function()use($stage,&$processed):void {$processed=$stage();});
            if (!$ok) { $failures[$key]??=$now; } elseif ($processed>0) { unset($failures[$key]); }
        }
        $count=0;$dispatchOk=$this->stage(function()use($reconcile,$now,&$count):void {
            $hints=$reconcile->due($now,true);$failed=false;
            foreach($hints as$hint) { if ($this->stage(fn()=>($this->dispatch)($hint))) { $count++; } else { $failed=true; } }
            if ($failed) { throw new \RuntimeException(); }
        });
        $q=DB::table('domains_watch_schedule')->where('scope','dispatch')->where('cursor',$run['turn']);
        DB::transaction(function()use($q,$failures,$dispatchOk,$now):void {
            $row=(clone$q)->lockForUpdate()->first();if (!$row) { return; }$data=json_decode($row->data??'{}',true);
            $data['local_failures']=$failures;
            $q->update(['data'=>json_encode($data),'progress_at'=>$dispatchOk?$now:$row->progress_at]);
        });
        (new WatchMaintenance())->tick($now);
        return $count;
    }
    private function stage(callable $run): bool
    {
        try { $run();return true; }
        catch (\Throwable) { \Illuminate\Support\Facades\Log::warning('Public domain scheduler stage failed.');return false; }
    }
    /** A few local reads a product, 25 a turn, so finding fields never eats the time public checks need. A product that fails stays No domain found, which already asks a person. */
    private function products(): void
    {
        $row=DB::table('domains_watch_schedule')->where('scope','products')->first();
        $products=WatchProducts::unmapped((int)($row->cursor??0),25);
        foreach($products as$product) { $this->stage(fn()=>WatchProducts::adopt($product)); }
        DB::table('domains_watch_schedule')->updateOrInsert(['scope'=>'products'],['cursor'=>$products->count()<25?0:$products->last()->id]);
    }
    private function enroll(int $now): int
    {
        $row=DB::table('domains_watch_schedule')->where('scope','enrollment')->first();
        $watches=WatchName::where('id','>',(int)($row->cursor??0))->orderBy('id')->limit(25)->get();
        $failed=false;foreach($watches as$w) {
            if (!$this->stage(function()use($w,$now):void {
                (new WatchRegistryChecks(true))->enroll((int)$w->id,(int)$w->user_id,$now);
                (new WatchDnsChecks(true))->enroll((int)$w->id,(int)$w->user_id,$now);
            })) { $failed=true; }
        }
        DB::table('domains_watch_schedule')->updateOrInsert(['scope'=>'enrollment'],['cursor'=>$watches->count()<25?0:$watches->last()->id]);
        if ($failed) { throw new \RuntimeException(); }
        return $watches->count();
    }
    public function status(): array
    {
        if (!$this->active() || !Schema::hasTable('domains_watch_schedule')) { return ['outage'=>false,'reason'=>'disabled','since'=>null]; }
        if (!$this->queueReady()) { return ['outage'=>true,'reason'=>'queue_configuration','since'=>null]; }
        return (new WatchMaintenance())->health(($this->clock)());
    }
    public function observe(array $hint): bool
    {
        if (!$this->active() || !$this->queueReady() || DB::transactionLevel()!==0 || !in_array($hint['kind']??null,['expiry','delegation','points'],true)) { return false; }
        $owner=(int)($hint['user_id']??0);
        if (isset($hint['watch_id'])) {
            return $hint['kind']==='expiry'?(new WatchRegistryObserver(new WatchRegistryChecks(true),$this->reader,$this->clock))->observe((int)$hint['watch_id'],$owner)
                :(new WatchDnsObserver(new WatchDnsChecks(true),$this->reader,$this->clock))->observe((int)$hint['watch_id'],$owner,$hint['kind']);
        }
        return isset($hint['domain_id']) && $hint['kind']!=='expiry' && $this->signals()->observe((int)$hint['domain_id'],$owner,$hint['kind']);
    }
}
