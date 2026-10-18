<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Tests;
use Paymenter\Extensions\Servers\Domains\Contracts\AdmittedRegistrarDriver;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AdmittedOperation, Capabilities, DriverResult, RequestContext};
/** A separate synthetic writer handle; never obtainable by upgrading a reader. */
final class FakeWriter implements AdmittedRegistrarDriver
{
    public array $calls = [];
    public function __construct(private readonly AccountBinding $account, private readonly Capabilities $capabilities,
        private readonly DriverResult $response)
    {
        $account->requireMatch($capabilities->binding); $account->requireMatch($response->binding);
    }
    private function simulate(Action $action, AdmittedOperation $operation, RequestContext $context): DriverResult
    {
        $this->account->requireMatch($operation->binding);
        if ($operation->query->action !== $action || !$this->capabilities->get($action->value)->allows($operation->query->years)) {
            throw new \InvalidArgumentException('Unsupported or mismatched admitted action/term.');
        }
        $context->claimRead($this->account); $this->calls[] = 'simulate_'.$action->value; return $this->response;
    }
    public function register(AdmittedOperation $operation, RequestContext $context): DriverResult { return $this->simulate(Action::Register, $operation, $context); }
    public function transferIn(AdmittedOperation $operation, RequestContext $context): DriverResult { return $this->simulate(Action::Transfer, $operation, $context); }
    public function renew(AdmittedOperation $operation, RequestContext $context): DriverResult { return $this->simulate(Action::Renew, $operation, $context); }
}
