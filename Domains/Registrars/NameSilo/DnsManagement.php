<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Data\{Capabilities,Capability,ManagementRequest,ManagementResult,RequestContext,Support};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Outcome;
/** Dormant opt-in adapter: no runtime factory or UI selects this class. */
class DnsManagement extends Management
{
    public function capabilities(): Capabilities
    {
        $base=parent::capabilities();$actions=['dns'=>new Capability(Support::Supported)];
        foreach (['nameservers','contacts','lock','privacy','auto_renew_off','auth_code_email','glue','ds','auth_code_display'] as $action) { $actions[$action]=$base->get($action); }
        return new Capabilities($this->binding,$actions,$base->privacy);
    }
    /** A confirmed DNS readback is a complete dnsListRecords inventory, so DNS observation is supported; other families keep the baseline. */
    public function observationCapabilities(): array
    { $base=parent::observationCapabilities();$base['families']['dns']='supported';return $base; }
    public function sequences(string $action): bool { return $action==='dns'; }
    public function validate(string $action,array $payload,string $name): array
    {
        if ($action!=='dns') { return parent::validate($action,$payload,$name); }
        $keys=array_keys($payload);$list=fn($v)=>is_array($v) && array_is_list($v) && count($v)<=50;
        if (($keys!==['records'] && $keys!==['records','preimage']) || !$list($payload['records']) || (isset($payload['preimage']) && !$list($payload['preimage']))) { throw new \DomainException('Invalid DNS records.'); }
        return $payload; // The complete, freshly read inventory validates observed IDs and opaque rows in plan(); a form's preimage must still match it.
    }
    public function plan(ManagementRequest $r,RequestContext $c): array
    {
        $this->owned($r,$c);$inventory=$this->inventory($r,$c);$seen=$r->payload()['preimage']??null;
        if ($seen!==null && DnsSequence::records(['state'=>'complete','records'=>$seen])!==DnsSequence::records($inventory)) { throw new \DomainException('DNS records changed.'); }
        $desired=$seen===null ? DnsSequence::correlate($r->name,$inventory,$r->payload()['records'],$r->sequence()['confirmed']??[]) : $r->payload()['records'];
        return ['preimage'=>$inventory,'steps'=>array_map(fn($delta)=>['action'=>'dns','input'=>['delta'=>$delta]],DnsSequence::plan($r->name,$inventory,$desired))];
    }
    protected function readCommands(): array { return [...parent::readCommands(),'dnsListRecords']; }
    protected function validateRead(string $command,array $p): void
    { parent::validateRead($command==='dnsListRecords'?'getDomainInfo':$command,$p); }
    protected function requestCommand(ManagementRequest $r): string
    { return $r->action==='dns'?DnsSequence::mapping($r->name,$r->payload()['delta'])->command:parent::requestCommand($r); }
    private function inventory(ManagementRequest $r,RequestContext $c): array
    { return Dns::inventory($this->read($r,$c,'dnsListRecords',['domain'=>$r->name]),$r->name); }
    public function prepare(ManagementRequest $r,RequestContext $c): array
    {
        if ($r->action!=='dns') { return parent::prepare($r,$c); }
        $this->owned($r,$c);
        if (DnsSequence::records($r->sequence()['preimage'])!==DnsSequence::records($this->inventory($r,$c))) { throw new \DomainException('DNS records changed.'); }
        DnsSequence::mapping($r->name,$r->payload()['delta']);return $r->payload();
    }
    protected function apply(ManagementRequest $r,RequestContext $c): ManagementResult
    {
        if ($r->action!=='dns') { return parent::apply($r,$c); }
        $mapping=DnsSequence::mapping($r->name,$r->payload()['delta']);
        $ack=Dns::acknowledgement($this->write($r,$c,$mapping->command,$mapping->parameters()),$mapping);
        if ($ack->outcome===Outcome::Success && is_string($ack->value('record_id'))) {
            $facts=['record_id'=>$ack->value('record_id')];$this->acknowledge($r,$c,$facts);
            $r=new ManagementRequest($r->binding,$r->key,$r->name,$r->action,$r->payload(),$r->providerId,domainId:$r->domainId,sequence:[...$r->sequence(),'ack'=>$facts]);
        }
        return $this->observe($r,$c);
    }
    public function observe(ManagementRequest $r,RequestContext $c): ManagementResult
    {
        if ($r->action!=='dns') { return parent::observe($r,$c); }
        $this->owned($r,$c);$observed=$this->inventory($r,$c);
        $proven=DnsSequence::confirm($r->sequence()['preimage'],$observed,$r->payload()['delta'],$r->sequence()['ack']['record_id']??null);
        return new ManagementResult($proven!==null,false,['sequence_preimage'=>$observed]);
    }
}
