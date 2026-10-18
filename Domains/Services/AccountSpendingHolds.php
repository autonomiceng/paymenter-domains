<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Shared engine account-wide in-flight spending gate, including prior generations. */
final class AccountSpendingHolds
{
    private const ACTIONS=['register','renew','transfer','privacy_purchase','privacy_renew'];
    public static function query(Builder $query): Builder
    {
        return $query->whereIn('state',['running','pending','uncertain'])
            ->where(fn($q)=>$q->where('funding_basis','paid_invoice')->orWhereIn('action',self::ACTIONS));
    }
    public static function matches(Operation $op): bool
    {
        return in_array($op->state,['running','pending','uncertain'],true)
            &&($op->funding_basis==='paid_invoice'||in_array($op->action,self::ACTIONS,true));
    }
}
