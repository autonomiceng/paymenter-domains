<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,OfferScheduler,OfferWork,UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Services\{DomainObservation,HoldingObservationWork};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;

/** Real shared scheduler with this test's observer; the retired facade ignores constructor injection.
 * Native leases, account rotation, generation guards and real fake-wire reads remain under test. */
function fixtureObservationWork(DomainObservation $observer,Clock $clock): OfferScheduler
{
    $generation=UpgradeGate::generation();
    return new OfferScheduler(CallerRouting::resolve(OfferWork::class),new HoldingObservationWork($observer,$clock),$clock,
        static fn(?int $server=null)=>CallerRouting::current($generation,$server));
}
