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
    /** Match the list's pending-provenance and latest-acquisition display overrides. */
    public static function status(Builder $q, array $states): Builder
    {
        if (!$states) { return $q; }
        return $q->where(function (Builder $group) use ($states): void {
            if (in_array('adoption_pending', $states, true)) {
                $group->orWhere(fn(Builder $pending)=>$pending->where('status','adoption_pending')->orWhere('provenance','adoption_pending'));
            }
            $stored=array_values(array_diff($states,['adoption_pending','held','uncertain']));
            $attention=array_values(array_intersect($states,['held','uncertain']));
            $group->orWhere(function (Builder $ordinary) use ($stored,$attention): void {
                $ordinary->where('status','!=','adoption_pending')->where(fn(Builder $p)=>$p->whereNull('provenance')->orWhere('provenance','!=','adoption_pending'));
                $ordinary->where(function (Builder $display) use ($stored,$attention): void {
                    $display->where(function (Builder $plain) use ($stored): void {
                        $plain->whereIn('status',$stored)->where(fn(Builder $review)=>$review->where('status','!=','needs_review')
                            ->orWhereDoesntHave('latestAcquisition',fn(Builder $op)=>$op->whereIn('state',['held','uncertain'])));
                    });
                    if ($attention) { $display->orWhere(fn(Builder $review)=>$review->where('status','needs_review')->whereHas('latestAcquisition',fn(Builder $op)=>$op->whereIn('state',$attention))); }
                });
            });
        });
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
