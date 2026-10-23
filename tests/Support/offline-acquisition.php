<?php
// Real action ports with explicitly supplied offline exchanges. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AcquisitionPort,AcquisitionPorts,OperationClaims,Rdap,RegistrationProfiles,TransferPrecheck,WriteAcceptance,WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
function offlineAcquisition(AccountBinding $binding,AccountBindings $bindings,Clock $clock,WriteFeatures $features,
    Closure $exchange,?TransferPrecheck $precheck=null):AcquisitionPorts
{
    $profiles=new RegistrationProfiles($binding,new OperationClaims($bindings,$clock),$clock,$exchange);
    $accepted=new WriteAcceptance($binding,true,true,25,$profiles);
    $registration=$binding->registrarKey==='enom'
        ?new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration($binding,$exchange)
        :new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Registration($binding,$profiles,$exchange);
    $transfer=$binding->registrarKey==='enom'
        ?new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Transfer($binding,$exchange)
        :new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Transfer($binding,$profiles,$exchange);
    $precheck??=new TransferPrecheck(new Rdap($exchange));
    return new AcquisitionPorts($binding,
        AcquisitionPort::registration($binding,$bindings,$clock,$features,$accepted,$registration,$profiles),
        AcquisitionPort::transfer($binding,$bindings,$clock,$features,$accepted,$transfer,$precheck,$profiles));
}
