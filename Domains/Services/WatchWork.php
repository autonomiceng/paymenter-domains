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
    public function enrollManaged(int $id,int $owner): void
    { $this->signals()->enqueue($id,$owner,($this->clock)()); }
    public function enrollWatch(int $id,int $owner): void
    {
        if(!$this->active()){return;}
        $now=($this->clock)();
        $this->stage(fn()=>(new WatchRegistryChecks(true))->enroll($id,$owner,$now));
        $this->stage(fn()=>(new WatchDnsChecks(true))->enroll($id,$owner,$now));
    }
    public function reconcileDefaults(): void
    {
        if(!$this->active() || DB::transactionLevel()!==0){return;}
        $now=($this->clock)();
        $this->stage(fn()=>(new ManagedPublicReconciliation($this->signals()))->sweep($now));
        $this->stage(fn()=>$this->enroll($now));
    }
    public function tick(): int
    {
        if (!$this->active() || !$this->queueReady() || DB::transactionLevel()!==0 || !Schema::hasTable('domains_watch_schedule')) { return 0; }
        $now=($this->clock)();
        $run=DB::transaction(function()use($now):?array {
            DB::table('domains_watch_schedule')->insertOrIgnore(['scope'=>'dispatch']);
            $q=DB::table('domains_watch_schedule')->where('scope','dispatch'); $row=$q->lockForUpdate()->first();
            if ($now<0 || $row->due_at>$now) { return null; }
            $q->update(['due_at'=>(intdiv($now,60)+1)*60,'data'=>$row->data??json_encode(['started_at'=>$now]),'cursor'=>(int)$row->cursor+1]); return ['turn'=>(int)$row->cursor+1,'data'=>json_decode($row->data??'{}',true)];
        });
        if (!$run) { return 0; }
        $failures=$run['data']['local_failures']??[];$discovered=0;
        if(!$this->stage(fn()=>$this->products())){$failures['products']??=$now;}else{unset($failures['products']);}
        $ok=$this->stage(function()use($now,&$discovered):void {$discovered=$this->discovery->tick($now);});
        if (!$ok || $this->discovery->failed) { $failures['discovery']??=$now; }
        elseif ($discovered>0) { unset($failures['discovery']); }
        $reconcile=new ManagedPublicReconciliation($this->signals());
        foreach(['managed_enrollment'=>fn()=>$reconcile->sweep($now),'watch_enrollment'=>fn()=>$this->enroll($now)]as$key=>$stage) {
            $processed=0;$ok=$this->stage(function()use($stage,&$processed):void {$processed=$stage();});
            if (!$ok) { $failures[$key]??=$now; } elseif ($processed>0) { unset($failures[$key]); }
        }
        $count=0;$dispatchOk=$this->stage(function()use($reconcile,$now,&$count):void {
            $hints=$reconcile->due(($this->clock)(),true);$failed=false;
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
        $failed=false;foreach($products as$product) { if(!$this->stage(fn()=>WatchProducts::adopt($product))){$failed=true;} }
        DB::table('domains_watch_schedule')->updateOrInsert(['scope'=>'products'],['cursor'=>$products->count()<25?0:$products->last()->id]);
        if ($failed) { throw new \RuntimeException(); }
    }
    private function enroll(int $now): int
    {
        // Claim before selecting the batch: a duplicate caller cannot repeat refresh side effects.
        $batch=DB::transaction(static function()use($now):?array {
            DB::table('domains_watch_schedule')->insertOrIgnore(['scope'=>'enrollment']);
            $q=DB::table('domains_watch_schedule')->where('scope','enrollment');$row=$q->lockForUpdate()->first();
            $data=json_decode($row->data??'{}',true);$data=is_array($data)?$data:[];
            if(($data['enrollment_until']??0)>$now){return null;}
            unset($data['enrollment_claim'],$data['enrollment_until']);
            $payload=json_encode([...$data,'enrollment_claim'=>bin2hex(random_bytes(16)),'enrollment_until'=>$now+120]);
            $q->update(['data'=>$payload]);return ['cursor'=>(int)$row->cursor,'data'=>$data,'payload'=>$payload];
        });
        if($batch===null){return 0;}
        $watches=WatchName::where('id','>',$batch['cursor'])->orderBy('id')->limit(25)->get();$data=$batch['data'];
        $failed=false;foreach($watches as$w) {
            $owned=DB::transaction(function()use($batch,$data,$w,$now):bool {
                $row=DB::table('domains_watch_schedule')->where('scope','enrollment')->where('data',$batch['payload'])->lockForUpdate()->first();
                if(!$row || ($this->clock)()>=$now+120){return false;}
                $refresh=$w->ns_profile_id===null?($data['refresh_dns']??false):in_array((int)$w->ns_profile_id,$data['refresh_profiles']??[],true);
                if($refresh && $w->state==='active'){
                    DB::table('domains_watch_work')->where('watch_id',$w->id)->whereIn('kind',['delegation','points'])
                        ->update(['due_at'=>$now,'claim'=>null,'lease_until'=>null,'backoff_until'=>null,'attempts'=>0,'safe_code'=>null]);
                }
                return true;
            });
            if(!$owned){return 0;}
            // These workers own their transactions and must not run inside the batch-claim transaction.
            if (!$this->stage(function()use($w,$now):void {
                (new WatchRegistryChecks(true))->enroll((int)$w->id,(int)$w->user_id,$now);
                (new WatchDnsChecks(true))->enroll((int)$w->id,(int)$w->user_id,$now);
            })) { $failed=true; }
        }
        $complete=!$failed && $watches->count()<25;
        DB::table('domains_watch_schedule')->where('scope','enrollment')->where('cursor',$batch['cursor'])->where('data',$batch['payload'])->update([
            'cursor'=>$failed?$batch['cursor']:($complete?0:$watches->last()->id),
            'data'=>json_encode([...$data,'refresh_dns'=>!$complete&&($data['refresh_dns']??false),'refresh_profiles'=>$complete?[]:($data['refresh_profiles']??[])])]);
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
