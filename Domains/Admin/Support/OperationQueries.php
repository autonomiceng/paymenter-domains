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
     * RequestStatus::STATES for each state, less the rows RequestStatus::READS_AS moves to another word, plus the rows it moves in.
     * $ordersOff: an ordersOffAccounts() snapshot, so the filter and the row words a list builds from it agree. Null reads the accounts now. */
    public static function status(Builder $q, array $keys, ?array $ordersOff=null): Builder
    {
        if (!$keys) { return $q; }
        $in=fn(string $state)=>in_array(RequestStatus::key($state),$keys,true);
        return $q->where(function($q) use ($in,$keys,$ordersOff) {
            if (in_array('on_hold',$keys,true)) { $q->orWhere(fn($held)=>self::heldAcquisition($held,$ordersOff)); }
            $q->orWhere(function($q) use ($in,$ordersOff) {
            $q->whereNot(fn($held)=>self::heldAcquisition($held,$ordersOff))->where(function($q) use ($in,$ordersOff) {
            // Matches nothing until a state matches. An empty group would be dropped and match every request.
            $q->whereRaw('0 = 1');
            foreach (array_keys(RequestStatus::STATES) as $state) {
                if (!$in($state)) { continue; }
                $q->orWhere(function($q) use ($state,$ordersOff) {
                    $q->where('state',$state);
                    foreach (RequestStatus::READS_AS as $rule) { if ($rule[0]===$state) { $q->whereNot(fn($q)=>self::readsAs($q,$rule,$ordersOff)); } }
                });
            }
            foreach (RequestStatus::READS_AS as $as=>$rule) { if ($in($as)) { $q->orWhere(fn($q)=>self::readsAs($q,$rule,$ordersOff)); } }
            });
            });
        });
    }
    /** SQL form of RequestFacts::heldAcquisition, including queued registrations stopped by Orders off and transfers staff held. */
    private static function heldAcquisition(Builder $q, ?array $ordersOff): Builder
    {
        return $q->where(fn($q)=>$q->where('action','register')->where(fn($q)=>$q
            ->where(fn($q)=>$q->where('state','held')->where(fn($q)=>$q->whereNull('safe_code')->orWhereNotIn('safe_code',RequestStatus::SETUP_CODES)))
            ->orWhere(fn($q)=>self::whereOrdersOff($q->where('state','queued'),$ordersOff))))
            ->orWhere(fn($q)=>$q->where('action','transfer')->where('state','held')->where('safe_code',RequestFacts::HELD_TRANSFER_CODE));
    }
    /** Orders are off for the request's own account. The word (RequestFacts::status) and the filter (whereOrdersOff) use this one rule.
     * With an ordersOffAccounts() snapshot it reads that, so every row of one list uses the same accounts. */
    public static function ordersOff(Operation $op, ?array $accounts=null): bool
    {
        if ($accounts!==null) { return $op->account_fingerprint!==null&&isset($accounts[self::accountKey($op->server_id,$op->registrar_key,$op->account_fingerprint)]); }
        return Account::where('processing_enabled',false)->where('server_id',$op->server_id)->where('registrar_key',$op->registrar_key)
            ->where('account_fingerprint',$op->account_fingerprint)->exists();
    }
    /** The accounts with Orders off, read once. A list passes this to its Status filter and its row words.
     * @return array<string,array{0:int,1:string,2:string}> by accountKey() */
    public static function ordersOffAccounts(): array
    {
        $accounts=[];
        // A missing fingerprint matches no request, as in SQL.
        foreach (Account::query()->toBase()->where('processing_enabled',false)->whereNotNull('account_fingerprint')->get(['server_id','registrar_key','account_fingerprint']) as $a) {
            $accounts[self::accountKey($a->server_id,$a->registrar_key,$a->account_fingerprint)]=[(int)$a->server_id,(string)$a->registrar_key,(string)$a->account_fingerprint];
        }
        return $accounts;
    }
    private static function accountKey(mixed $server, mixed $registrar, mixed $fingerprint): string
    { return (int)$server."\0".$registrar."\0".$fingerprint; }
    private static function whereOrdersOff(Builder $q, ?array $accounts): Builder
    {
        if ($accounts!==null) {
            // Matches nothing until an account matches. An empty group would be dropped and match every request.
            return $q->where(function($q) use ($accounts) {
                $q->whereRaw('0 = 1');
                foreach ($accounts as [$server,$registrar,$fingerprint]) {
                    $q->orWhere(fn($q)=>$q->where('domains_operations.server_id',$server)->where('domains_operations.registrar_key',$registrar)
                        ->where('domains_operations.account_fingerprint',$fingerprint));
                }
            });
        }
        return $q->whereExists(fn($a)=>$a->from('domains_accounts')->where('domains_accounts.processing_enabled',false)
            ->whereColumn('domains_accounts.server_id','domains_operations.server_id')->whereColumn('domains_accounts.registrar_key','domains_operations.registrar_key')
            ->whereColumn('domains_accounts.account_fingerprint','domains_operations.account_fingerprint'));
    }
    /** One RequestStatus::READS_AS rule. Codes need a value, so NOT of a rule keeps rows with no code. */
    private static function readsAs(Builder $q, array $rule, ?array $ordersOff): Builder
    {
        [$state,$codes,$off]=$rule; $q->where('state',$state);
        if ($codes!==null) { $q->whereNotNull('safe_code')->whereIn('safe_code',$codes); }
        return $off ? self::whereOrdersOff($q,$ordersOff) : $q;
    }
    public static function paidUncertainty(Builder $q): Builder
    {
        return $q->where('funding_basis','paid_invoice')->where(function($q) {
            $q->whereIn('state',['running','pending','uncertain'])->orWhere('safe_code','transfer_provider_rejected')
                ->orWhereIn('safe_code',['transfer_needs_action','management_provider_refused']);
        });
    }
}
