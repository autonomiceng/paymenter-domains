<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Order,Service,User};
use Illuminate\Support\Facades\{DB,Log,Schema};
use Paymenter\Extensions\Servers\Domains\Models\Domain;

/** Diagnostic only. No deletion veto, dispatch authority or automatic native reconstruction. */
final class NativeIdentityDiscrepancies
{
    public static function boot(): void
    {
        if(!HookRegistration::claim(Service::getEventDispatcher(),self::class)){return;}
        foreach([User::class=>'user_id',Service::class=>'service_id'] as $class=>$column){
            $class::deleted(static function($row)use($column):void {
                try {
                    if(!Schema::hasTable('domains_domains')||!Schema::hasTable('domains_obligation_alerts')){return;}
                    $ids=array_filter([$row->getRawOriginal('id'),$row->id]);
                    // Keep the native action bounded. The durable runtime domain cursor catches the rest.
                    foreach(Domain::whereIn($column,$ids)->orderBy('id')->limit(25)->get() as $d){self::inspect($d,now()->getTimestamp());}
                } catch(\Throwable $e){Log::warning('Domains native deletion discrepancy check deferred.',['exception_class'=>$e::class]);}
            });
        }
    }
    public static function inspect(Domain $seed,int $now): bool
    {
        return DB::transaction(static function()use($seed,$now):bool {
            $d=Domain::whereKey($seed->id)->lockForUpdate()->firstOrFail();
            $service=Service::find($d->service_id);
            $missing=!User::whereKey($d->user_id)->exists()||!$service||!Order::whereKey($service->order_id)->exists();
            if($missing){ObligationAlerts::record($d,'native_identity_missing',$now);}
            return !$missing;
        });
    }
}
