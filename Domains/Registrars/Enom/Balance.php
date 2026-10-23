<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ProtocolResult;
final class Balance
{
    public static function read(#[\SensitiveParameter] ProtocolResult $reply): ?Money
    {
        $value = $reply->value('availablebalance');
        if ($reply->status !== 'success' || !is_string($value)
            || !preg_match('/^(?:0|[1-9][0-9]*|[1-9][0-9]{0,2}(?:,[0-9]{3})+)(?:\.[0-9]{1,2})?$/D', $value)) { return null; }
        try { return new Money(str_replace(',', '', $value).(str_contains($value, '.') ? '' : '.00'), 'USD'); }
        catch (\InvalidArgumentException) { return null; }
    }
}
