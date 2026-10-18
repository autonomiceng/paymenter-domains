<?php
// Synthetic fake only: no provider client, native extension or real write bridge.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Tests;

use Paymenter\Extensions\Servers\Domains\Contracts\RegistrarDriver;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AdmittedOperation, AttemptEvidence,
    Capabilities, DriverResult, Query, ReconciliationResult, RequestContext};

final class FakeRegistrar implements RegistrarDriver
{
    public array $calls = [];
    public array $reconciliationReferences = [];
    public function __construct(private readonly AccountBinding $account, private readonly Capabilities $capabilities,
        private readonly DriverResult $response)
    {
        $account->requireMatch($capabilities->binding);
        $account->requireMatch($response->binding);
    }
    public function binding(): AccountBinding { return $this->account; }
    private function read(string $method, RequestContext $context): DriverResult
    {
        $context->claimRead($this->account);
        $this->calls[] = $method;
        return $this->response;
    }
    public function accountCapabilities(RequestContext $context): Capabilities
    {
        $this->read('capabilities', $context); return $this->capabilities;
    }
    public function balance(RequestContext $context): DriverResult { return $this->read('balance', $context); }
    public function quote(Query $query, RequestContext $context): DriverResult { return $this->read('quote', $context); }
    public function availability(Query $query, RequestContext $context): DriverResult { return $this->read('availability', $context); }
    public function readDomain(string $name, RequestContext $context): DriverResult
    {
        Query::requireName($name); return $this->read('domain', $context);
    }
    public function renewalAssessment(Query $query, RequestContext $context): DriverResult
    {
        if ($query->action !== Action::Renew) { throw new \InvalidArgumentException('Renewal assessment requires renew action.'); }
        return $this->read('renewal_assessment', $context);
    }
    public function reconcile(AttemptEvidence $attempt, RequestContext $context): ReconciliationResult
    {
        $this->account->requireMatch($attempt->binding);
        $this->reconciliationReferences[] = $attempt->providerReference;
        return new ReconciliationResult($attempt, $this->read('reconcile', $context));
    }
}

final class FakeClock implements \Paymenter\Extensions\Servers\Domains\Contracts\Clock
{
    public function __construct(public \DateTimeImmutable $instant) {}
    public function now(): \DateTimeImmutable { return $this->instant; }
}
