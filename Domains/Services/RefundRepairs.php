<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** Repair evidence commits after a failed money transaction rolls back. The ordinary sweep retries it. */
final class RefundRepairs
{
    public static function failed(int $id,int $now): void
    {
        if(!Schema::hasTable('domains_obligation_alerts')){return;}
        $op=Operation::find($id);if(!$op||$op->state!=='rejected'){return;}
        DB::transaction(function()use($op,$now):void {
            $d=Domain::whereKey($op->domain_id)->lockForUpdate()->firstOrFail();
            ObligationAlerts::record($d,'refund_repair',$now,(int)$op->id);
        });
    }
    public static function resolved(int $id,int $now): void
    {
        if(!Schema::hasTable('domains_obligation_alerts')){return;}
        if(Operation::whereKey($id)->value('state')!=='rejected'){return;}
        DB::table('domains_obligation_alerts')->where('operation_id',$id)->whereIn('code',['refund_repair','funds_wait_review'])->whereNull('resolved_at')->update(['resolved_at'=>$now]);
    }
}
