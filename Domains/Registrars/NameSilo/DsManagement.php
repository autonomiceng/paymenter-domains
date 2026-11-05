<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, Capability, ManagementRequest, ManagementResult, RequestContext, Support, SystemClock};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\DsMapping;

/** Dormant sequence adapter. C1 alone selects it and wires physical admission. */
final class DsManagement extends DnsManagement
{
    public function __construct(AccountBinding $binding, #[\SensitiveParameter] \Closure $exchange,
        private readonly array $facts = [], private readonly Clock $clock = new SystemClock)
    { parent::__construct($binding, $exchange, $facts); }

    public function capabilities(): Capabilities
    {
        $base = parent::capabilities();
        $actions = [];
        foreach (['nameservers','contacts','lock','privacy','auto_renew_off','auth_code_email','glue','dns','auth_code_display'] as $action) {
            $actions[$action] = $base->get($action);
        }
        $eligible = array_filter($this->dsRegistryFacts($this->clock->now()), static fn($fact) => $fact['input'] === 'ds');
        $actions['ds'] = new Capability($eligible ? Support::Supported : Support::Unsupported);
        return new Capabilities($this->binding, $actions, $base->privacy);
    }

    public function dsMutationPolicy(string $name): ?array
    { return (new DsRegistryPolicy($this->facts, $this->clock->now()))->snapshot($name); }

    private static function dsAction(string $action): bool { return in_array($action, ['ds_add','ds_delete'], true); }
    public function sequences(string $action): bool { return self::dsAction($action) || parent::sequences($action); }

    public function validate(string $action, #[\SensitiveParameter] array $payload, string $name): array
    {
        if (!self::dsAction($action)) { return parent::validate($action, $payload, $name); }
        if (array_diff(array_keys($payload), ['ds','confirm_last_delete']) || !is_array($payload['ds'] ?? null)
            || (array_key_exists('confirm_last_delete', $payload) && !is_bool($payload['confirm_last_delete']))) {
            throw new \DomainException('Invalid DS request.');
        }
        $tuple = Ds::tuple($payload['ds']);
        $policy = new DsRegistryPolicy($this->facts, $this->clock->now());
        $action === 'ds_add' ? DsMapping::add($name, $tuple, $policy) : DsMapping::delete($name, $tuple, $policy);
        return ['ds'=>$tuple, ...(array_key_exists('confirm_last_delete', $payload) ? ['confirm_last_delete'=>$payload['confirm_last_delete']] : [])];
    }

    public function plan(ManagementRequest $r, RequestContext $c): array
    {
        if (!self::dsAction($r->action)) { return parent::plan($r, $c); }
        $input = $this->validate($r->action, $r->payload(), $r->name);
        $this->owned($r, $c);
        $inventory = $this->inventory($r, $c); $records = Ds::records($inventory);
        $present = in_array($input['ds'], $records, true);
        if (($r->action === 'ds_delete') !== $present || ($r->action === 'ds_add' && count($records) >= 50)) {
            throw new \DomainException('DS tuple precondition changed.');
        }
        if ($r->action === 'ds_delete' && count($records) === 1 && ($input['confirm_last_delete'] ?? false) !== true) {
            throw new \DomainException('Confirm deletion of the last DS record.');
        }
        $policy = $this->dsMutationPolicy($r->name);
        if ($policy === null) { throw new \DomainException('DS registry facts unavailable.'); }
        return ['preimage'=>[...$inventory, 'ds_policy'=>$policy], 'steps'=>[['action'=>$r->action, 'input'=>$input]]];
    }

    protected function readCommands(): array { return [...parent::readCommands(), 'dnsSecListRecords']; }
    protected function validateRead(string $command, #[\SensitiveParameter] array $p): void
    { parent::validateRead($command === 'dnsSecListRecords' ? 'getDomainInfo' : $command, $p); }
    protected function requestCommand(ManagementRequest $r): string
    { return self::dsAction($r->action) ? $this->mapping($r, true)->command : parent::requestCommand($r); }

    private function inventory(ManagementRequest $r, RequestContext $c): array
    { return Ds::inventory($this->read($r, $c, 'dnsSecListRecords', DsMapping::listRecords($r->name)->parameters())); }

    /** Expiry/revocation blocks new sends; a frozen policy still describes an already sent delta for readback. */
    private function mapping(ManagementRequest $r, bool $sending): DsMapping
    {
        $snapshot = $r->sequence()['preimage']['ds_policy'] ?? null;
        if (!is_array($snapshot) || ($snapshot['format'] ?? null) !== 1 || !is_string($snapshot['suffix'] ?? null)
            || ($sending && $this->dsMutationPolicy($r->name) !== $snapshot)) {
            throw new \DomainException('Frozen DS policy unavailable or changed.');
        }
        try { $at = new \DateTimeImmutable($snapshot['reviewed_at'] ?? 'invalid'); }
        catch (\Throwable) { throw new \DomainException('Invalid frozen DS policy.'); }
        $fact = array_diff_key($snapshot, ['format'=>true]);
        $policy = new DsRegistryPolicy(['format'=>1, 'entries'=>[$snapshot['suffix']=>$fact]], $at);
        return $r->action === 'ds_add' ? DsMapping::add($r->name, $r->payload()['ds'], $policy)
            : DsMapping::delete($r->name, $r->payload()['ds'], $policy);
    }

    public function prepare(ManagementRequest $r, RequestContext $c): array
    {
        if (!self::dsAction($r->action)) { return parent::prepare($r, $c); }
        $this->mapping($r, true); $this->owned($r, $c);
        if (Ds::records($r->sequence()['preimage']) !== Ds::records($this->inventory($r, $c))) {
            throw new \DomainException('DS inventory changed.');
        }
        $this->mapping($r, true);
        return $r->payload();
    }

    protected function apply(ManagementRequest $r, RequestContext $c): ManagementResult
    {
        if (!self::dsAction($r->action)) { return parent::apply($r, $c); }
        $mapping = $this->mapping($r, true);
        // The inherited one-use engine grant remains the only path to a physical mutation.
        $this->write($r, $c, $mapping->command, $mapping->parameters());
        return $this->observe($r, $c);
    }

    public function observe(ManagementRequest $r, RequestContext $c): ManagementResult
    {
        if (!self::dsAction($r->action)) { return parent::observe($r, $c); }
        $mapping = $this->mapping($r, false); $this->owned($r, $c);
        $observed = $this->inventory($r, $c);
        return new ManagementResult(Ds::confirm($mapping, $r->sequence()['preimage'], $observed), false, ['sequence_preimage'=>$observed]);
    }
}
