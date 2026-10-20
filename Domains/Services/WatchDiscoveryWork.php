<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\WatchProductMap;
/** Durable scheduler for the accepted discovery producer. Hints coalesce; native events never perform public I/O. */
final class WatchDiscoveryWork
{
    public bool $failed=false;
    public function __construct(private ?\Closure $discover=null) {}
    public function ready(): bool { return $this->discover!==null; }
    public function hint(int $product): void
    {
        if (!$this->ready() || $product<1) { return; }
        try {
            DB::table('domains_watch_discovery')->insertOrIgnore(['product_id'=>$product]);
            DB::table('domains_watch_discovery')->where('product_id',$product)->update(['hint'=>true]);
        } catch (\Throwable) { \Illuminate\Support\Facades\Log::warning('Public domain discovery hint deferred; daily sweep repairs lost hints.'); }
    }
    public function tick(int $now): int
    {
        if (!$this->ready() || DB::transactionLevel()!==0 || $now<0) { return 0; }
        foreach(WatchProductMap::where(fn($q)=>$q->where('enabled',true)->orWhereExists(fn($s)=>$s->selectRaw('1')->from('domains_watch_sources as s')->whereColumn('s.map_id','domains_watch_product_maps.id')))->whereNotIn('product_id',DB::table('domains_watch_discovery')->select('product_id'))->orderBy('product_id')->limit(100)->pluck('product_id')as$id) {
            DB::table('domains_watch_discovery')->insertOrIgnore(['product_id'=>$id]);
        }
        $total=0;$this->failed=false;
        for($page=0;$page<4;$page++) {
            $turn=DB::transaction(function()use($now) {
                $q=DB::table('domains_watch_discovery');
                $r=(clone$q)->where(fn($q)=>$q->where('due_at','<=',$now)->orWhere('hint',true))
                    ->whereExists(fn($q)=>$q->selectRaw('1')->from('domains_watch_product_maps as m')->whereColumn('m.product_id','domains_watch_discovery.product_id')->where(fn($m)=>$m->where('m.enabled',true)->orWhereExists(fn($s)=>$s->selectRaw('1')->from('domains_watch_sources as s')->whereColumn('s.map_id','m.id'))))
                    ->orderBy('turn')->orderBy('product_id')->lockForUpdate()->first();
                if ($r) { $r->turn=(int)$q->max('turn')+1;(clone$q)->where('product_id',$r->product_id)->update(['turn'=>$r->turn,'hint'=>$r->cursor>0?(bool)$r->hint:false,'due_at'=>$now+300]); }
                return $r;
            });
            if (!$turn) { break; }
            // Retained source IDs let the accepted producer retire deleted/moved native services.
            $native=Service::where('product_id',$turn->product_id)->whereExists(fn($q)=>$q->selectRaw('1')->from('domains_watch_product_maps as m')->whereColumn('m.product_id','services.product_id')->where('m.enabled',true))->select('id');
            $retained=DB::table('domains_watch_sources as s')->join('domains_watch_product_maps as m','m.id','=','s.map_id')->where('m.product_id',$turn->product_id)->select('s.service_id as id');
            $ids=DB::query()->fromSub($native->union($retained),'candidates')->where('id','>',$turn->cursor)->orderBy('id')->limit(25)->pluck('id');
            // Accepted producer rechecks native owner/property/map and writes local source history atomically.
            foreach($ids as$id) {
                try { ($this->discover)((int)$id,(int)$turn->product_id); }
                catch (\Throwable) { $this->failed=true;\Illuminate\Support\Facades\Log::warning('Public domain discovery failed.',['service_id'=>(int)$id]); }
                $total++;
            }
            DB::transaction(function()use($turn,$ids,$now):void {
                $q=DB::table('domains_watch_discovery')->where('product_id',$turn->product_id);$current=$q->lockForUpdate()->first();
                if ($current && (int)$current->cursor===(int)$turn->cursor && (int)$current->turn===(int)$turn->turn) {
                    $q->update($ids->count()<25?['cursor'=>0,'due_at'=>$current->hint?$now:$now+86400]:['cursor'=>$ids->last(),'due_at'=>$now]);
                }
            });
        }
        return $total;
    }
}
