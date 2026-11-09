<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchProductMap,WatchSource};
/** Local hints only. Native writes retain their own locks and all authority. */
final class WatchDiscoveryHints
{
    /** @param list<array{0:int,1:mixed}> $pairs Current and previous owner/name identities, never a cross-owner name lookup. */
    public static function names(array $pairs): void
    {
        $valid=[];
        foreach($pairs as[$owner,$name]){
            if($owner<1||!is_string($name)||$name===''){continue;}
            try{\Paymenter\Extensions\Servers\Domains\Data\CanonicalName::requireCanonical($name);$valid[$owner.':'.$name]=[$owner,$name];}catch(\InvalidArgumentException){}
        }
        if(!$valid){return;}
        DB::afterCommit(static function()use($valid):void{
            if(!app(WatchDiscoveryWork::class)->ready()){return;}
            try{
                $sources=WatchSource::whereNotNull('service_id')->where(function($q)use($valid):void{
                    foreach($valid as[$owner,$name]){$q->orWhere(fn($q)=>$q->where('user_id',$owner)->where('registrable_name',$name));}
                })->select('map_id');
                $products=WatchProductMap::whereIn('id',$sources)->where('product_id','>',0)->select('product_id')->distinct()->orderBy('product_id')->limit(100)->pluck('product_id');
                foreach($products as$product){app(WatchDiscoveryWork::class)->hint((int)$product);}
            }catch(\Throwable){\Illuminate\Support\Facades\Log::warning('Public domain managed link hint deferred.');}
        });
    }
}
