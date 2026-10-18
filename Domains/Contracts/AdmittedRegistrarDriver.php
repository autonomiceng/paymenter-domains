<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;

use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation, DriverResult, RequestContext};

/** Future engine-only write seam. The admission/claim verifier and real wire bridge are deferred. */
interface AdmittedRegistrarDriver
{
    public function register(AdmittedOperation $operation, RequestContext $context): DriverResult;
    public function transferIn(AdmittedOperation $operation, RequestContext $context): DriverResult;
    public function renew(AdmittedOperation $operation, RequestContext $context): DriverResult;
}
