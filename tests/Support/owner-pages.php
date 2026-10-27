<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native fixture composition only.
declare(strict_types=1);
require_once __DIR__.'/storefront.php';
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, DomainManagement, HolderPricing, NativeDomainGuard, OwnedDomains, OwnerPorts};
$ports=new OwnerPorts(static function($b) {
    $class='Paymenter\\Extensions\\Servers\\Domains\\Registrars\\'.($b->registrarKey==='enom'?'Enom':'NameSilo').'\\Management';
    return new $class($b,static fn()=>throw new RuntimeException('No owner page may perform I/O.'));
});
$ownerBilling=new DomainBilling($bindings,new NativeDomainGuard(new HolderPricing($prices),$clock),$clock);
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\Storefront::class,new \Paymenter\Extensions\Servers\Domains\Services\Storefront($sellers,$prices,$native,$bindings,new \Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility($bindings,$clock),$clock));
$owners=new OwnedDomains($clock,$ports,new DomainManagement($bindings,$clock),$ownerBilling);
require dirname(__DIR__,2).'/Domains/routes/web.php';
require dirname(__DIR__,2).'/Domains/routes/owned.php';
storefrontViews();

foreach(['PslSchema','PslReviewSchema','WatchSchema','WatchDnsSchema','ManagedPublicWorkSchema','ManagedPublicSchema','WatchScheduleSchema','WatchNoticeSchema','AdoptionSchema','NativeIntentSchema'] as $schema){$class='Paymenter\\Extensions\\Servers\\Domains\\Services\\'.$schema;$class::install();}
