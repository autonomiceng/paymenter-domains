<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Exact holding binding, independent of today's seller. No financial interpretation of state labels. */
final class DomainQueries
{
    public static function operations(Domain $d): Builder
    {
        return $d->operations()->getQuery()->where('service_id',$d->service_id)->where('server_id',$d->server_id)
            ->where('registrar_key',$d->registrar_key)->where('account_fingerprint',$d->account_fingerprint)
            ->where('authority_generation',$d->authority_generation);
    }
    /** Rows whose status (DomainFacts::status) falls in one of the DomainStatus::SET keys.
     * The rule reads encrypted request data and has one copy, so rows are sorted in PHP, 200 at a time. */
    public static function status(Builder $q, array $keys): Builder
    {
        if (!$keys) { return $q; }
        $ids=[];
        (clone $q)->reorder()->with('latestAcquisition')->chunkById(200,function ($page) use (&$ids,$keys): void {
            $inputs=StaffDomainInputs::for($page);
            foreach ($page as $d) {
                if (in_array(DomainFacts::status($d,$inputs[$d->id])['key'],$keys,true)) { $ids[]=$d->id; }
            }
        },'domains_domains.id','id');
        return $q->whereIntegerInRaw('domains_domains.id',$ids);
    }
    public static function attention(Builder $q, array $states = ['held','uncertain']): Builder
    {
        return self::related($q,fn($ops)=>$ops->whereIn('attention.state',$states));
    }
    public static function creditReview(Builder $q): Builder
    {
        return self::related($q,fn($ops)=>RefundCandidates::query($ops,'attention.'));
    }
    public static function registrarRejected(Builder $q): Builder
    {
        return self::related($q,fn($ops)=>$ops->where('attention.action','transfer')->where('attention.state','rejected')
            ->where('attention.safe_code','transfer_provider_rejected'));
    }
    private static function related(Builder $q, \Closure $conditions): Builder
    {
        // Correlated SQL keeps filters bounded and includes NameSilo's legitimate no-ID operations.
        return $q->whereExists(function ($ops) use ($conditions) {
            $ops->selectRaw('1')->from('domains_operations as attention')
                ->whereColumn('attention.domain_id','domains_domains.id')
                ->whereColumn('attention.service_id','domains_domains.service_id')
                ->whereColumn('attention.server_id','domains_domains.server_id')
                ->whereColumn('attention.registrar_key','domains_domains.registrar_key')
                ->whereColumn('attention.account_fingerprint','domains_domains.account_fingerprint')
                ->whereColumn('attention.authority_generation','domains_domains.authority_generation');
            $conditions($ops);
        });
    }
}
