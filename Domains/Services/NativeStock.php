<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Product;
/** Availability belongs to owned publication. Native stock arithmetic on NULL stays NULL. */
final class NativeStock
{
    public static function boot(): void
    {
        static $booted=false;if($booted){return;}$booted=true;
        Product::saving(static function(Product $p):void {
            if(!NativeIdentity::productRows(NativeIdentity::rows($p))){return;}
            foreach($p->getAttributes()as$key=>$value){$parts=explode('.',explode('->',$key,2)[0]);
                if(strtolower(end($parts))==='stock'&&$value!==null){
                    logger()->warning('Managed domain finite stock refused.',['product_id'=>$p->getRawOriginal('id')??$p->id]);
                    $message='Domain products require unlimited stock. Withdraw offers on the Domains page.';
                    throw \Illuminate\Validation\ValidationException::withMessages(['stock'=>$message,'data.stock'=>$message]);
                }
            }
        });
        // increment/decrement skips saving and supplies a SQL expression after updating.
        // NULL +/- quantity is NULL; never refuse the qualified native cron stock increment.
        Product::updated(static function(Product $p):void {
            if(NativeIdentity::productRows(NativeIdentity::rows($p))){$p->stock=null;}
        });
    }
    public static function normalize(): void
    {
        Product::whereNotNull('stock')->orderBy('id')->chunkById(100,static function($products):void {
            foreach($products as$p){if(NativeIdentity::productRows([$p->getAttributes()])){
                \Illuminate\Support\Facades\DB::table('products')->where('id',$p->id)->update(['stock'=>null]);
            }}
        });
    }
}
