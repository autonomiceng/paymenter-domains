<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};
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
    /** Requests whose word (RequestFacts::status) is one of the RequestStatus::SET keys. Built from the same map as the word:
     * RequestStatus::STATES for each state, less the rows RequestStatus::READS_AS moves to another word, plus the rows it moves in. */
    public static function status(Builder $q, array $keys): Builder
    {
        if (!$keys) { return $q; }
        $in=fn(string $state)=>in_array(RequestStatus::key($state),$keys,true);
        return $q->where(function($q) use ($in,$keys) {
            if (in_array('on_hold',$keys,true)) { $q->orWhere(fn($held)=>self::heldAcquisition($held)); }
            $q->orWhere(function($q) use ($in) {
            $q->whereNot(fn($held)=>self::heldAcquisition($held))->where(function($q) use ($in) {
            // Matches nothing until a state matches. An empty group would be dropped and match every request.
            $q->whereRaw('0 = 1');
            foreach (array_keys(RequestStatus::STATES) as $state) {
                if (!$in($state)) { continue; }
                $q->orWhere(function($q) use ($state) {
                    $q->where('state',$state);
                    foreach (RequestStatus::READS_AS as $rule) { if ($rule[0]===$state) { $q->whereNot(fn($q)=>self::readsAs($q,$rule)); } }
                });
            }
            foreach (RequestStatus::READS_AS as $as=>$rule) { if ($in($as)) { $q->orWhere(fn($q)=>self::readsAs($q,$rule)); } }
            });
            });
        });
    }
    /** SQL form of RequestFacts::heldAcquisition, including queued registrations stopped by Orders off and transfers staff held. */
    private static function heldAcquisition(Builder $q): Builder
    {
        return $q->where(fn($q)=>$q->where('action','register')->where(fn($q)=>$q
            ->where(fn($q)=>$q->where('state','held')->where(fn($q)=>$q->whereNull('safe_code')->orWhereNotIn('safe_code',RequestStatus::SETUP_CODES)))
            ->orWhere(fn($q)=>self::whereOrdersOff($q->where('state','queued')))))
            ->orWhere(fn($q)=>$q->where('action','transfer')->where('state','held')->where('safe_code',RequestFacts::HELD_TRANSFER_CODE));
    }
    /** Orders are off for the request's own account. The word (RequestFacts::status) and the filter (whereOrdersOff) use this one rule. */
    public static function ordersOff(Operation $op): bool
    {
        return Account::where('processing_enabled',false)->where('server_id',$op->server_id)->where('registrar_key',$op->registrar_key)
            ->where('account_fingerprint',$op->account_fingerprint)->exists();
    }
    private static function whereOrdersOff(Builder $q): Builder
    {
        return $q->whereExists(fn($a)=>$a->from('domains_accounts')->where('domains_accounts.processing_enabled',false)
            ->whereColumn('domains_accounts.server_id','domains_operations.server_id')->whereColumn('domains_accounts.registrar_key','domains_operations.registrar_key')
            ->whereColumn('domains_accounts.account_fingerprint','domains_operations.account_fingerprint'));
    }
    /** One RequestStatus::READS_AS rule. Codes need a value, so NOT of a rule keeps rows with no code. */
    private static function readsAs(Builder $q, array $rule): Builder
    {
        [$state,$codes,$off]=$rule; $q->where('state',$state);
        if ($codes!==null) { $q->whereNotNull('safe_code')->whereIn('safe_code',$codes); }
        return $off ? self::whereOrdersOff($q) : $q;
    }
    public static function paidUncertainty(Builder $q): Builder
    {
        return $q->where('funding_basis','paid_invoice')->where(function($q) {
            $q->whereIn('state',['running','pending','uncertain'])->orWhere('safe_code','transfer_provider_rejected')
                ->orWhereIn('safe_code',['transfer_needs_action','management_provider_refused']);
        });
    }
}
