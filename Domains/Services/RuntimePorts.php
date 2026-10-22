<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
/** Deployment-owned, account-bound executors. Absent acceptance is a hard refusal, never a sender fallback. */
final class RuntimePorts
{
    private array $factories=[];
    public const TYPES=['acquisition'=>AcquisitionPorts::class,'reconciliation'=>OperationReconciliation::class,
        'renewal'=>RenewalExecution::class,'renewal_writer'=>RenewalWriter::class,'renewal_completion'=>RenewalCompletion::class,
        'management'=>DomainManagement::class,'management_protocol'=>\Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol::class];
    public function register(string $fingerprint,\Closure $factory): void
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)||isset($this->factories[$fingerprint])){throw new \DomainException('Duplicate or invalid runtime binding.');}
        $this->factories[$fingerprint]=$factory;
    }
    public function registered(Account $account): bool
    {
        if(isset($this->factories[$account->account_fingerprint])){return true;}
        try{return EnomTestRegistration::enabled(CatalogueAcceptances::binding($account))||EnomTestRenewal::enabled(CatalogueAcceptances::binding($account));}catch(\Throwable){return false;}
    }
    private function composition(Account $account): array
    {
        $binding=CatalogueAcceptances::binding($account);
        $factory=$this->factories[$account->account_fingerprint]??((EnomTestRegistration::enabled($binding)||EnomTestRenewal::enabled($binding))?EnomTestRegistration::factory($binding):null)
            ??throw new \DomainException('Runtime provider acceptance is required.');
        $ports=$factory(CatalogueAcceptances::binding($account));
        foreach(self::TYPES as $key=>$type){if(!($ports[$key]??null) instanceof $type){throw new \DomainException('Incomplete runtime composition.');}}
        $ports['acquisition']->requireBinding(CatalogueAcceptances::binding($account));
        if(!$ports['management_protocol']->bindsTo(CatalogueAcceptances::binding($account))){throw new \DomainException('Runtime management binding changed.');}
        return $ports;
    }
    public function accepted(Account $account): bool {try{$this->composition($account);return true;}catch(\Throwable){return false;}}
    /** Local composition and feature facts only; these never confer processing or provider acceptance. */
    public function acquisitionGates(Account $account): array
    {
        try { return $this->composition($account)['acquisition']->gates(); }
        catch (\Throwable) { return ['register'=>null,'transfer'=>null]; }
    }
    public function management(\Paymenter\Extensions\Servers\Domains\Data\AccountBinding $binding): \Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol
    { $a=Account::where('server_id',$binding->serverId)->firstOrFail();$binding->requireMatch(CatalogueAcceptances::binding($a));return $this->composition($a)['management_protocol']; }
    public function managementEngine(\Paymenter\Extensions\Servers\Domains\Data\AccountBinding $binding): DomainManagement
    {$a=Account::where('server_id',$binding->serverId)->firstOrFail();$binding->requireMatch(CatalogueAcceptances::binding($a));return $this->composition($a)['management'];}
    public function operation(Operation $op): void
    {
        if($op->state==='awaiting_payment'&&\App\Models\Invoice::whereKey($op->invoice_id)->value('status')!=='paid'){return;}
        if($op->action==='transfer'&&in_array($op->state,['awaiting_payment','awaiting_code','queued'],true)&&!TransferInputs::validCode($op->payload['epp']??null)){return;}
        $a=Account::where('server_id',$op->server_id)->firstOrFail();$d=Domain::findOrFail($op->domain_id);
        if($a->account_fingerprint!==$op->account_fingerprint||$d->authority_generation!==$op->authority_generation){throw new \DomainException('Runtime authority changed.');}
        $ports=$this->composition($a);
        if(isset($op->policy['management'])){
            if(in_array($op->state,['queued','awaiting_payment'],true)){$ports['management']->run((int)$op->id,$ports['management_protocol'],\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background);}
            elseif(in_array($op->state,['pending','uncertain','running'],true)){$ports['management']->reconcile((int)$op->id,$ports['management_protocol']);}
        }elseif($op->action==='renew'){
            if(in_array($op->state,['queued','awaiting_payment'],true)){$ports['renewal']->run((int)$op->id,$ports['renewal_writer']);}
            elseif(in_array($op->state,['pending','uncertain','running'],true)){$ports['renewal_completion']->run((int)$op->id);}
        }elseif(in_array($op->action,['register','transfer'],true)){
            if(in_array($op->state,['queued','awaiting_payment','awaiting_code'],true)){if(NativePaidHooks::serviceMissing()){return;}$ports['acquisition']->execute((int)$op->id);}
            elseif(in_array($op->state,['pending','uncertain','running'],true)){$ports['reconciliation']->run((int)$op->id);}
        }
    }
    /** The sole named attachment point for initial managed-record work; registration itself grants no account authority. */
    private ?\Closure $initialManagedRecordObservations=null;
    public function registerInitialManagedRecordObservations(\Closure $producer): void
    {if($this->initialManagedRecordObservations){throw new \LogicException('Initial managed-record producer already registered.');}$this->initialManagedRecordObservations=$producer;}
    public function initialManagedRecordObservations(): void {($this->initialManagedRecordObservations??static fn()=>null)();}
}
