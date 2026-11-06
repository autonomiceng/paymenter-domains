<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic shared scheduler composition.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,OfferScheduler,OfferWork,UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,HoldingObservationWork};

/** Holding tests use the real shared rotation with their reader and clock. The offer side
 * has no provider allowance; offer protocol/execution has separate synthetic regressions. */
function observationFixtureScheduler(DomainObservation $observer,$clock,$ports): OfferScheduler
{
    $generation=UpgradeGate::generation();
    $current=static fn(?int $server=null)=>CallerRouting::current($generation,$server);
    $offers=new OfferWork($ports->writer(),$clock,new AccountReadBudget($clock,25,25),
        static fn()=>throw new LogicException('No offer I/O in holding fixture.'),static fn()=>0,
        generation:$generation,guard:$current);
    return new OfferScheduler($offers,new HoldingObservationWork($observer,$clock),$clock,$current);
}
