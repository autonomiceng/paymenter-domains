<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native fixture composition only.
declare(strict_types=1);
require_once __DIR__.'/storefront.php';
use Paymenter\Extensions\Servers\Domains\Services\{OwnedDomains, OwnerPorts};
$ports=new OwnerPorts(static function($b) {
    $class='Paymenter\\Extensions\\Servers\\Domains\\Registrars\\'.($b->registrarKey==='enom'?'Enom':'NameSilo').'\\Management';
    return new $class($b,static fn()=>throw new RuntimeException('No owner page may perform I/O.'));
});
$owners=new OwnedDomains($clock,$ports,new \Paymenter\Extensions\Servers\Domains\Services\DomainManagement($bindings,$clock));
require dirname(__DIR__,2).'/Domains/routes/web.php';
require dirname(__DIR__,2).'/Domains/routes/owned.php';
storefrontViews();
