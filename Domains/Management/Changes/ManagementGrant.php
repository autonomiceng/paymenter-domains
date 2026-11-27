<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Management\Changes;

use Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol;
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest, RequestContext};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AttemptToken, ReadToken};

/** Process-local permission prepared outside the locks and consumed inside them. */
final readonly class ManagementGrant
{
    public function __construct(public ManagementRequest $request, public RequestContext $context,
        public ManagementProtocol $driver, public string $stamp, public Domain $seed, public int $operationId,
        public AttemptToken $attempt, public ?ReadToken $read = null) {}

    public function matches(ManagementRequest $request, RequestContext $context, ManagementProtocol $driver): bool
    {
        return $this->request === $request && $this->context === $context && $this->driver === $driver;
    }

    public function sealed(ReadToken $read): self
    {
        return new self($this->request, $this->context, $this->driver, $this->stamp, $this->seed, $this->operationId, $this->attempt, $read);
    }
}
