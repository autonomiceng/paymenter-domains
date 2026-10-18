<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
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
    public static function paidUncertainty(Builder $q): Builder
    {
        return $q->where('funding_basis','paid_invoice')->where(function($q) {
            $q->whereIn('state',['running','pending','uncertain'])->orWhere('safe_code','transfer_provider_rejected')
                ->orWhereIn('safe_code',['transfer_needs_action','management_provider_refused']);
        });
    }
}
