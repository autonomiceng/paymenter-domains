<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
final class OperationQueries
{
    public static function bound(Builder $q): Builder
    {
        return $q->whereHas('domain',function($d) {
            foreach (['service_id','server_id','registrar_key','account_fingerprint','authority_generation'] as $key) {
                $d->whereColumn('domains_domains.'.$key,'domains_operations.'.$key);
            }
        });
    }
    /** Requests whose pill (Labels::requestStatus) falls in one of the RequestStatus::SET keys. A rejected request reads Failed or Cancelled by its code. */
    public static function status(Builder $q, array $keys): Builder
    {
        if (!$keys) { return $q; }
        $states=array_keys(array_filter(RequestStatus::STATES,fn($state)=>in_array($state[0],$keys,true)));
        return $q->where(function($q) use ($states,$keys) {
            $q->whereIn('state',array_diff($states,['rejected']));
            if (in_array('failed',$keys,true)) { $q->orWhere(fn($q)=>$q->where('state','rejected')->where(fn($q)=>$q->whereNull('safe_code')->orWhereNotIn('safe_code',RequestStatus::CLOSED_CODES))); }
            if (in_array('cancelled',$keys,true)) { $q->orWhere(fn($q)=>$q->where('state','rejected')->whereIn('safe_code',RequestStatus::CLOSED_CODES)); }
        });
    }
    public static function paidUncertainty(Builder $q): Builder
    {
        return $q->where('funding_basis','paid_invoice')->where(function($q) {
            $q->whereIn('state',['running','pending','uncertain'])->orWhere('safe_code','transfer_provider_rejected')
                ->orWhereIn('safe_code',['transfer_needs_action','management_provider_refused']);
        });
    }
}
