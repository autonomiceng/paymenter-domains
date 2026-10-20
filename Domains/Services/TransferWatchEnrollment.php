<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,WatchName,WatchSource};
/** Local cleanup follows proved LIVE acquisition, independently of offer/public-read activation. */
final class TransferWatchEnrollment
{
    public function repair(int $id,int $owner,int $now): bool
    {
        if (!Schema::hasTable('domains_watch_names')) { return false; }
        $d=Domain::whereKey($id)->where('user_id',$owner)->first();
        if (!$d || !Account::where('server_id',$d->server_id)->where('account_fingerprint',$d->account_fingerprint)->where('environment','live')->exists()
            || !Operation::where('domain_id',$id)->where('authority_generation',$d->authority_generation)->where('action','transfer')->where('state','succeeded')->exists()) { return false; }
        $pending=WatchName::where('user_id',$owner)->where('canonical_name',$d->domain)->whereIn('state',['active','paused'])->exists()
            || WatchSource::where('user_id',$owner)->where('registrable_name',$d->domain)->where('state','watch')->exists();
        if (!$pending) { $this->resolve($id,$now);return false; }
        try { $result=(new ManagedPublicEnrollment())->reconcile($id,$owner,$now,transferOnly:true); }
        catch (\Throwable $e) { logger()->warning('Transfer watch enrollment deferred.',['domain_id'=>$id,'exception_class'=>$e::class]);$result=null; }
        if ($result===null) { ObligationAlerts::record($d,'transfer_watch_relink',$now);return false; }
        $this->resolve($id,$now);
        return $result;
    }
    private function resolve(int $id,int $now): void
    {
        DB::table('domains_obligation_alerts')->where('domain_id',$id)->where('code','transfer_watch_relink')->whereNull('resolved_at')->update(['resolved_at'=>$now]);
    }
}
