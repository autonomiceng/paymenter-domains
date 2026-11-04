<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Contracts\LiveReservationPolicy;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
/** OTE-only limits. Local TEST composition does not grant any Live authority. */
final class TestReservationPolicy implements LiveReservationPolicy
{
    public function limits(AccountBinding $binding): array
    {
        ActivationEvidence::testAccount($binding);
        return ['daily_call_cap'=>config('domains.namesilo_test.daily_call_cap',1000),
            'daily_spend_limit_usd'=>config('domains.namesilo_test.daily_spend_limit_usd','1000.00')];
    }
    public function requireFeature(AccountBinding $binding,string $feature): void
    {
        $account=ActivationEvidence::testAccount($binding);
        if(!in_array($feature,['catalogue','registration','transfer','renewal','management'],true)
            ||config('domains.namesilo_test.'.$feature,true)!==true
            ||$feature!=='catalogue'&&!$account->processing_enabled){throw new \DomainException('TEST NameSilo feature off.');}
    }
}
