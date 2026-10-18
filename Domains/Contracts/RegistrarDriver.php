<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AttemptEvidence, Capabilities, DriverResult, Query, ReconciliationResult, RequestContext};

interface RegistrarDriver
{
    public function binding(): AccountBinding;
    public function accountCapabilities(RequestContext $context): Capabilities;
    public function balance(RequestContext $context): DriverResult;
    /** Catalogue normalization/registry schemas are added with the actual read adapters. */
    public function quote(Query $query, RequestContext $context): DriverResult;
    public function availability(Query $query, RequestContext $context): DriverResult;
    public function readDomain(string $name, RequestContext $context): DriverResult;
    public function renewalAssessment(Query $query, RequestContext $context): DriverResult;
    public function reconcile(AttemptEvidence $attempt, RequestContext $context): ReconciliationResult;
}
