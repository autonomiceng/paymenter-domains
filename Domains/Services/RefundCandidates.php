<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\{Eloquent\Builder, Query\Builder as Query};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Shared queue selection only. Encrypted funding is checked separately under holding locks.
 * A candidate is never proof of credit eligibility; SQL intentionally includes credited rows.
 */
final class RefundCandidates
{
    private const ACTIONS = ['register','renew','transfer','privacy_purchase','privacy_renew'];
    public static function query(Builder|Query $q, string $prefix = ''): Builder|Query
    {
        return $q->where($prefix.'state','rejected')->where($prefix.'funding_basis','paid_invoice')
            ->whereIn($prefix.'action',self::ACTIONS)->where(fn($q)=>$q->whereNull($prefix.'safe_code')
                ->orWhere($prefix.'safe_code','!=','transfer_provider_rejected'));
    }
    public static function matches(Operation $op, array $funding): bool
    {
        return $op->state==='rejected' && $op->funding_basis==='paid_invoice' && in_array($op->action,self::ACTIONS,true)
            && $op->safe_code!=='transfer_provider_rejected' && !array_key_exists('refund',$funding);
    }
}
