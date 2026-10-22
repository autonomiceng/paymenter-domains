<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,ManagementProtocol,RegistrationProtocol,TransferProtocol};
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
/** Explicit deployment factory. No credentials, default exchanges, provider reads or processing changes. */
final class RuntimeComposition
{
    public static function make(AccountBinding $binding, AccountBindings $bindings, Clock $clock, WriteFeatures $features,
        WriteAcceptance $accepted, RenewalAcceptance $renewalAccepted, TransferPrecheck $precheck,
        RegistrationProtocol $registration, TransferProtocol $transfer, #[\SensitiveParameter] \Closure $renewalExchange,
        ManagementProtocol $management, bool $managementAccepted=false, ?RegistrationProfiles $profiles=null, bool $testManagement=false): array
    {
        if (!$management->bindsTo($binding)) { throw new \DomainException('Runtime management binding changed.'); }
        $acquisition=new AcquisitionPorts($binding,
            AcquisitionPort::registration($binding,$bindings,$clock,$features,$accepted,$registration,$profiles),
            AcquisitionPort::transfer($binding,$bindings,$clock,$features,$accepted,$transfer,$precheck,$profiles));
        $authority=new RenewalAuthority($bindings,$clock);
        $renewal=new RenewalExecution($bindings,$authority,new RenewalAdmission($authority,$bindings,$renewalAccepted,$clock),$renewalAccepted,$features,$clock);
        return ['acquisition'=>$acquisition,'reconciliation'=>new OperationReconciliation(new OperationClaims($bindings,$clock),$bindings,$clock),
            'renewal'=>$renewal,'renewal_writer'=>new RenewalWriter($binding,$features,$renewalAccepted,static fn()=>$renewal,$renewalExchange),
            'renewal_completion'=>new RenewalCompletion($bindings,$clock),
            'management'=>new DomainManagement($bindings,$clock,$features,$managementAccepted,testManagement:$testManagement),'management_protocol'=>$management];
    }
}
