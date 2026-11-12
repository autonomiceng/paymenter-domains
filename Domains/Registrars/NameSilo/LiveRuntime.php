<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,Query};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,LiveActivation,NameSiloFeatureSettings,OperationClaims,ProfileContactOperations,RegistrationProfiles,RenewalAcceptance,RuntimeComposition,TransferPrecheck,WriteAcceptance,WriteFeatures};
/** Installed Live assembly. Local construction grants no activation or processing authority. */
final class LiveRuntime
{
    public static function enabled(AccountBinding $binding): bool
    {
        try {
            $evidence=new ActivationEvidence;
            if(!$evidence->enabled($binding,'catalogue')){return false;}
            $evidence->receiptContext($binding);
            return true;
        }catch(\Throwable){return false;}
    }
    private static function deployment(AccountBinding $binding): array
    {return (new ActivationEvidence)->receiptContext($binding);}
    public static function factory(AccountBinding $binding): \Closure
    {
        if(!self::enabled($binding)){throw new \DomainException('Live NameSilo runtime unavailable.');}
        $bindings=app(AccountBindings::class);$clock=app(Clock::class);
        $revision=$bindings->credentialRevision($binding);$deployment=self::deployment($binding);$settings=NameSiloFeatureSettings::stamp($binding);
        $registrationAccepted=(new LiveActivation(new ActivationEvidence))->registrationAccepted($binding);
        return static function(AccountBinding $requested)use($binding,$bindings,$clock,$revision,$deployment,$settings,$registrationAccepted):array {
            $binding->requireMatch($requested);$bindings->requireCredentialRevision($binding,$revision);
            if(!self::enabled($binding)||$deployment!==self::deployment($binding)||$settings!==NameSiloFeatureSettings::stamp($binding)){
                throw new \DomainException('Live NameSilo runtime changed.');
            }
            $current=static function(AccountBinding $b)use($binding,$bindings,$revision,$deployment,$settings,$registrationAccepted):bool {
                try {
                    $binding->requireMatch($b);$bindings->requireCredentialRevision($binding,$revision);
                    return self::enabled($b)&&$deployment===self::deployment($b)&&$settings===NameSiloFeatureSettings::stamp($b)
                        &&(new LiveActivation(new ActivationEvidence))->current($b)
                        &&(new LiveActivation(new ActivationEvidence))->registrationAccepted($b)===$registrationAccepted;
                }catch(\Throwable){return false;}
            };
            $credentials=static function(bool $write)use($binding,$bindings,$revision,$deployment,$current):array {
                OperationClaims::outside();
                if(!self::enabled($binding)||$deployment!==self::deployment($binding)){throw new \DomainException('Live NameSilo runtime changed.');}
                if($write){
                    if(!$current($binding)){throw new \DomainException('Live NameSilo acceptance unavailable.');}
                    $bindings->requireProcessing($binding);
                }
                return $bindings->offerCredentials($binding,$revision);
            };
            $transport=new AdmittedTransport($binding,$credentials);$exchange=$transport->exchange();
            $profileTransport=new AdmittedTransport($binding,$credentials);
            $profiles=new RegistrationProfiles($binding,new OperationClaims($bindings,$clock),$clock,$exchange,
                operations:new ProfileContactOperations($binding,new OperationClaims($bindings,$clock),$clock,$profileTransport->reservation,$profileTransport->exchange()));
            $activation=new LiveActivation(new ActivationEvidence);
            $features=new WriteFeatures(registration:(new ActivationEvidence)->enabled($binding,'registration')&&$activation->registrationAccepted($binding),
                transfer:NameSiloFeatureSettings::accepted($binding,'transfer'),renewal:NameSiloFeatureSettings::accepted($binding,'renewal'),
                management:NameSiloFeatureSettings::accepted($binding,'management'));
            $check=static fn(AccountBinding $b,bool $lock=false,?Query $query=null):bool=>$current($b)
                &&($query===null||($query->action===Action::Transfer?NameSiloFeatureSettings::accepted($b,'transfer'):$activation->registrationAccepted($b)));
            $accepted=new WriteAcceptance($binding,true,true,25,$profiles,$check);
            $renewal=new RenewalAcceptance($binding,true,true,25,localCheck:static fn($b)=>$current($b)&&NameSiloFeatureSettings::accepted($b,'renewal'));
            $registration=new Registration($binding,$profiles,$exchange);$registration->useReservation($transport->reservation);
            $transfer=new Transfer($binding,$profiles,$exchange);$transfer->useReservation($transport->reservation);
            $management=new DsManagement($binding,$exchange,DsRegistryPolicy::DEPLOYMENT_FACTS,$clock);$management->useReservation($transport->reservation);
            $ports=RuntimeComposition::make($binding,$bindings,$clock,$features,$accepted,$renewal,app(TransferPrecheck::class),
                $registration,$transfer,$exchange,$management,$features->management,$profiles,
                observationReader:new ObservationReader($binding,$exchange),
                managementAcceptanceCheck:static fn($b)=>$current($b)&&NameSiloFeatureSettings::accepted($b,'management'));
            $ports['renewal_writer']->useReservation($transport->reservation);
            return $ports;
        };
    }
    /** Read-only recovery stays available with Orders paused or a withdrawn write receipt. */
    public static function reader(AccountBinding $binding,Clock $clock): \Closure
    {
        if(!self::enabled($binding)){throw new \DomainException('Live NameSilo reader unavailable.');}
        $bindings=app(AccountBindings::class);$deployment=self::deployment($binding);
        return $bindings->nameSiloRuntimeReaderFactory($binding,$clock,static function(AccountBinding $b,string $revision)use($binding,$bindings,$deployment):bool {
            try {
                $binding->requireMatch($b);$bindings->requireCredentialRevision($b,$revision);
                return self::enabled($b)&&$deployment===self::deployment($b);
            }catch(\Throwable){return false;}
        });
    }
}
