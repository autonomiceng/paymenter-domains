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
    /** Rows whose page status (DomainFacts::status) falls in one of the DomainStatus::SET keys. Auto-renew off near expiry
     * (Action required) needs decrypted policy, so those rows stay under Active here. */
    public static function status(Builder $q, array $keys): Builder
    {
        if (!$keys) { return $q; }
        $today=gmdate('Y-m-d');
        // Null-safe so whereNot keeps rows whose provenance is NULL.
        $is=fn(string $column)=>fn(Builder $c)=>$c->whereNotNull($column)->where($column,'adoption_pending');
        $pending=fn(Builder $b)=>$b->where(fn(Builder $p)=>$p->where($is('status'))->orWhere($is('provenance')));
        $acquiring=fn(Builder $b,array $states,?\Closure $op=null)=>$b->whereIn('status',['registration_pending','transfer_pending','needs_review'])
            ->whereHas('latestAcquisition',fn(Builder $o)=>$op?$op($o->whereIn('state',$states)):$o->whereIn('state',$states));
        $setup=fn(Builder $o)=>$o->where('state','held')->whereIn('safe_code',['contact_details_required','nameservers_required']);
        $notSetup=fn(Builder $o)=>$o->where(fn(Builder $c)=>$c->where('state','!=','held')->orWhereNull('safe_code')->orWhereNotIn('safe_code',['contact_details_required','nameservers_required']));
        $late=fn(Builder $b)=>AcquisitionPaymentReview::query($b);
        // Not imported-pending, not shown from the latest acquisition and not paid late: the status column decides.
        $plain=fn(Builder $b)=>$b->whereNot($pending)->whereNot(fn(Builder $o)=>$acquiring($o,['awaiting_payment','awaiting_code','held','uncertain']))->whereNot($late);
        $stored=fn(array $statuses)=>fn(Builder $b)=>$plain($b)->whereIn('status',$statuses);
        $known=['active','registration_pending','transfer_pending','needs_review','expired','grace','redemption','registration_rejected','transfer_rejected','cancelled'];
        $rules=[
            'active'=>fn(Builder $b)=>$plain($b)->where('status','active')->whereDate('provider_expires_at','>',$today),
            'pending_registration'=>fn(Builder $b)=>$b->where(fn(Builder $o)=>$stored(['registration_pending'])($o))
                ->orWhere(fn(Builder $o)=>$acquiring($o->whereNot($pending)->whereNot($late),['held','uncertain'],fn(Builder $op)=>$notSetup($op->where('action','register')))),
            'pending_transfer'=>$stored(['transfer_pending']),
            'action_required'=>fn(Builder $b)=>$acquiring($b->whereNot($pending)->whereNot($late),['awaiting_payment','awaiting_code','held'],
                fn(Builder $op)=>$op->where(fn(Builder $c)=>$c->whereIn('state',['awaiting_payment','awaiting_code'])->orWhere(fn(Builder $h)=>$setup($h)))),
            'under_review'=>fn(Builder $b)=>$b->where($pending)->orWhere($late)
                ->orWhere(fn(Builder $o)=>$acquiring($o->whereNot($pending),['held','uncertain'],fn(Builder $op)=>$notSetup($op->where('action','!=','register'))))
                ->orWhere(fn(Builder $o)=>$plain($o)->where(fn(Builder $r)=>$r->whereIn('status',['needs_review'])->orWhereNotIn('status',$known)->orWhereNull('status')
                    ->orWhere(fn(Builder $a)=>$a->where('status','active')->whereNull('provider_expires_at')))),
            'expired'=>fn(Builder $b)=>$plain($b)->where(fn(Builder $r)=>$r->whereIn('status',['expired','grace'])
                ->orWhere(fn(Builder $a)=>$a->where('status','active')->whereDate('provider_expires_at','<=',$today))),
            'redemption'=>$stored(['redemption']),
            'failed'=>$stored(['registration_rejected','transfer_rejected']),
            'cancelled'=>$stored(['cancelled']),
        ];
        return $q->where(function (Builder $group) use ($keys,$rules): void {
            foreach (array_intersect(array_keys($rules),$keys) as $key) { $group->orWhere(fn(Builder $b)=>$rules[$key]($b)); }
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
