<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

enum AcquisitionLane: string
{
    case Purchase = 'purchase';
    case BalancePurchase = 'balance_purchase';
    case BalanceWait = 'balance_wait';
    case FundsWait = 'funds_wait';
    case PriceWait = 'price_wait';
    case ContactWait = 'contact_wait';

    public function ownsBalance(): bool
    {
        return $this === self::BalanceWait || $this === self::BalancePurchase;
    }
}
