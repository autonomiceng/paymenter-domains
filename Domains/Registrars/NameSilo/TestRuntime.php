<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,OperationClaims,ProfileContactOperations,RegistrationProfiles,RenewalAcceptance,RuntimeComposition,TransferPrecheck,WriteAcceptance,WriteFeatures,NameSiloFeatureSettings};
/** Built-in OTE composition. Construction is local; every physical send rechecks the saved revision. */
final class TestRuntime
{
    public static function enabled(AccountBinding $binding,?string $feature=null): bool
    {
        try {
            ActivationEvidence::testAccount($binding);
            return config('domains.namesilo_test.catalogue',true)===true
                &&($feature===null||in_array($feature,['registration','transfer','renewal','management'],true)
                    &&config('domains.namesilo_test.'.$feature,true)===true);
        }catch(\Throwable){return false;}
    }
    public static function factory(AccountBinding $binding): \Closure
    {
        if(!self::enabled($binding)){throw new \DomainException('TEST NameSilo runtime unavailable.');}
        $bindings=app(AccountBindings::class);$clock=app(Clock::class);
        $revision=$bindings->credentialRevision($binding);$settings=NameSiloFeatureSettings::stamp($binding);
        return static function(AccountBinding $requested)use($binding,$bindings,$clock,$revision,$settings):array {
            $binding->requireMatch($requested);$bindings->requireCredentialRevision($binding,$revision);
            $transport=new AdmittedTransport($binding,static function(bool $write)use($binding,$bindings,$revision,$settings):array {
                OperationClaims::outside();
                if(!self::enabled($binding)){throw new \DomainException('TEST NameSilo runtime unavailable.');}
                if($write){$bindings->requireProcessing($binding);if($settings!==NameSiloFeatureSettings::stamp($binding)){throw new \DomainException('NameSilo settings changed.');}}
                return $bindings->offerCredentials($binding,$revision);
            });
            $exchange=$transport->exchange();
            $profiles=new RegistrationProfiles($binding,new OperationClaims($bindings,$clock),$clock,$exchange,
                operations:new ProfileContactOperations($binding,new OperationClaims($bindings,$clock),$clock,$transport->reservation,$exchange));
            $features=new WriteFeatures(registration:self::enabled($binding,'registration'),transfer:NameSiloFeatureSettings::accepted($binding,'transfer'),
                renewal:NameSiloFeatureSettings::accepted($binding,'renewal'),management:NameSiloFeatureSettings::accepted($binding,'management'));
            $check=static function(AccountBinding $current)use($binding,$bindings,$revision,$settings):bool {
                try {$binding->requireMatch($current);$bindings->requireCredentialRevision($binding,$revision);return self::enabled($current)&&$settings===NameSiloFeatureSettings::stamp($current);}
                catch(\Throwable){return false;}
            };
            $accepted=new WriteAcceptance($binding,true,true,25,$profiles,$check);
            $renewal=new RenewalAcceptance($binding,true,true,25,localCheck:static fn($b)=>$check($b)&&NameSiloFeatureSettings::accepted($b,'renewal'));
            $registration=new Registration($binding,$profiles,$exchange);$registration->useReservation($transport->reservation);
            $transfer=new Transfer($binding,$profiles,$exchange);$transfer->useReservation($transport->reservation);
            $management=new DsManagement($binding,$exchange,DsRegistryPolicy::DEPLOYMENT_FACTS,$clock);$management->useReservation($transport->reservation);
            $ports=RuntimeComposition::make($binding,$bindings,$clock,$features,$accepted,$renewal,app(TransferPrecheck::class),
                $registration,$transfer,$exchange,$management,$features->management,$profiles,
                observationReader:new ObservationReader($binding,$exchange));
            $ports['renewal_writer']->useReservation($transport->reservation);
            return $ports;
        };
    }
    public static function reader(AccountBinding $binding,Clock $clock): \Closure
    {
        $bindings=app(AccountBindings::class);
        return $bindings->nameSiloRuntimeReaderFactory($binding,$clock,static function(AccountBinding $b,string $revision)use($bindings):bool {
            try{$bindings->requireCredentialRevision($b,$revision);return self::enabled($b);}catch(\Throwable){return false;}
        });
    }
}
