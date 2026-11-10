<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,Environment,HistoryAcceptance};
/** NameSilo's registerDomain reply has no order number, so only its order history can confirm the charge.
 * Measured on the OTE test service: a registration order is listed at once, dated in UTC within a second of its send.
 * TEST accounts only. A saved setting wins; Live NameSilo keeps its dated activation evidence. */
final class TestOrderHistory
{
    public const TIMEZONE='UTC',LAG=120,RETENTION=2592000;
    public static function acceptance(AccountBinding $binding,Action $action): ?HistoryAcceptance
    {
        if($action!==Action::Register||$binding->registrarKey!=='namesilo'||$binding->environment!==Environment::Test
            ||config('domains.namesilo_account.environment')!=='test'){return null;}
        return new HistoryAcceptance($binding,self::TIMEZONE,self::LAG,self::RETENTION,'namesilo_registration_v1');
    }
}
