<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** TEST inbound transfers reuse the audited catalogue route, annual terms and USD predicates. */
final class EnomTestTransfer
{
    public static function enabled(AccountBinding $b): bool
    { return self::deploymentEnabled()&&TestCatalogue::enabled($b); }
    public static function deploymentEnabled(): bool
    { return config('domains.enom_writes.transfer')===true&&config('domains.enom_identity.environment')==='test'&&config('domains.enom_catalogue.enabled')===true; }
    public static function applies(Operation $op): bool
    { return self::deploymentEnabled()&&$op->action==='transfer'&&$op->registrar_key==='enom'&&in_array($op->state,['queued','awaiting_payment','awaiting_code'],true); }
    public static function reason(): string
    {
        if(config('domains.enom_writes.transfer')!==true){return 'TEST inbound transfer is off in deployment configuration.';}
        if(config('domains.enom_identity.environment')!=='test'){return 'TEST inbound transfer refused: the deployment identity environment is not test. Live and unset environments cannot use TEST transfers.';}
        if(config('domains.enom_catalogue.enabled')!==true){return 'TEST inbound transfer refused: the deployment TEST catalogue is off.';}
        return 'Deployment allows TEST inbound transfer through TP_CreateOrder. Review catalogue acceptance for annual transfers, registry details, the fixed TEST route and request interval; separately enable processing and the transfer TLD. A recent successful USD balance read and owner transfer code are required. Status and accepted charge history must confirm completion. No provider-supported TEST transfer fixture has been accepted.';
    }
}
