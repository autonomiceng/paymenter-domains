<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,OperationClaims};
/** Dormant installed reader assembly; no registration, feature acceptance or write authority by construction. */
final class ReadRuntime
{
    public static function factory(AccountBinding $binding,Clock $clock,#[\SensitiveParameter] \Closure $credentials,
        #[\SensitiveParameter] \Closure $revision,#[\SensitiveParameter] ?\Closure $accepted=null): \Closure
    {
        if($binding->registrarKey!=='namesilo'){throw new \InvalidArgumentException('NameSilo reader required.');}
        $secrets=new \SensitiveParameterValue([$credentials,$revision,$accepted]);
        return static function(AccountBinding $requested)use($binding,$clock,$secrets):Driver {
            try {
                OperationClaims::outside();$binding->requireMatch($requested);
                [$credentials,$revision,$accepted]=$secrets->getValue();
                $stamp=$revision();
                $current=static function(bool $write)use($binding,$credentials,$accepted,$stamp):array {
                    try {
                        // This port must never acquire a mutation grant, even if a later caller misuses it.
                        if($write||!$accepted||$accepted($binding,$stamp)!==true){throw new \DomainException;}
                        ($binding->environment===Environment::Live?new NameSiloLiveReservationPolicy:new TestReservationPolicy)->requireFeature($binding,'catalogue');
                        return $credentials($stamp);
                    }catch(\Throwable){throw new \DomainException('NameSilo runtime read acceptance unavailable.');}
                };
                $key=$current(false)['api_key'];
                if($caps=\Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities::routed()){
                    $actions=array_values(array_filter(['register','renew','transfer'],static fn($action)=>$caps->endings($binding,$action)!==[]));
                    return new Driver(new Client($binding,$key,new AdmittedTransport($binding,$current)),$actions,$clock);
                }
                throw new \DomainException(\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE);
            }catch(\Throwable){throw new \DomainException('NameSilo runtime reader unavailable.');}
        };
    }
}
