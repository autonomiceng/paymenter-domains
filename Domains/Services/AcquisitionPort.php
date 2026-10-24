<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,RegistrationProtocol,TransferProtocol};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** A fixed action, real preflight and real bridge; construction performs no reads or sends. */
final readonly class AcquisitionPort
{
    private function __construct(private AccountBinding $binding, public Action $action,
        private DomainOperations $engine, private WriteFeatures $features, private WriteAcceptance $accepted,
        private ?RegistrationProfiles $profiles) {}
    public static function registration(AccountBinding $binding, AccountBindings $bindings, Clock $clock,
        WriteFeatures $features, WriteAcceptance $accepted, RegistrationProtocol $protocol, ?RegistrationProfiles $profiles=null): self
    {
        if ($protocol instanceof TransferProtocol) { throw new \DomainException('Registration protocol action mismatch.'); }
        return self::compose($binding,$bindings,$clock,$features,$accepted,$protocol,
            new RegistrationPreflight($bindings,$features,$accepted,$profiles),Action::Register,$profiles);
    }
    public static function transfer(AccountBinding $binding, AccountBindings $bindings, Clock $clock,
        WriteFeatures $features, WriteAcceptance $accepted, TransferProtocol $protocol, TransferPrecheck $precheck,
        ?RegistrationProfiles $profiles=null): self
    {
        return self::compose($binding,$bindings,$clock,$features,$accepted,$protocol,
            new TransferPreflight($bindings,$features,$accepted,$precheck,$profiles),Action::Transfer,$profiles);
    }
    private static function compose(AccountBinding $binding, AccountBindings $bindings, Clock $clock, WriteFeatures $features,
        WriteAcceptance $accepted, RegistrationProtocol $protocol, RegistrationPreflight|TransferPreflight $preflight, Action $action,
        ?RegistrationProfiles $profiles): self
    {
        $writerClass=match($binding->registrarKey){
            'enom'=>\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Writer::class,
            'namesilo'=>\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Writer::class,
            default=>throw new \DomainException('Acquisition registrar unavailable.')};
        $engine=null;
        $writer=new $writerClass($binding,$features,$accepted,static function()use(&$engine){return $engine;},$protocol);
        $writer->checkComposition($preflight);
        $engine=new DomainOperations(new OperationClaims($bindings,$clock),$preflight,$writer,$clock);
        return new self($binding,$action,$engine,$features,$accepted,$profiles);
    }
    public function requireSharedPolicy(self $other): void
    {
        if ($this->features!==$other->features || $this->accepted!==$other->accepted || $this->profiles!==$other->profiles) {
            throw new \DomainException('Acquisition policy objects differ.');
        }
    }
    public function requireRoute(AccountBinding $binding, Action $action): void
    {
        $this->binding->requireMatch($binding);
        if ($this->action!==$action) { throw new \DomainException('Acquisition action mismatch.'); }
    }
    public function enabled(): bool { return $this->action===Action::Register?$this->features->registration:$this->features->transfer; }
    public function execute(int $id): Operation
    {
        $op=Operation::findOrFail($id);
        if ($op->action!==$this->action->value || (int)$op->server_id!==$this->binding->serverId
            || $op->registrar_key!==$this->binding->registrarKey || $op->account_fingerprint!==$this->binding->fingerprint) {
            throw new \DomainException('Acquisition operation binding changed.');
        }
        return $this->engine->execute($id);
    }
}
