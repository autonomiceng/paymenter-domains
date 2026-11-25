<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Dispatch;
use Paymenter\Extensions\Servers\Domains\Contracts\{AdmittedRegistrarDriver, WireGate};
use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation, RequestContext};
use Paymenter\Extensions\Servers\Domains\Services\OperationClaims;
/** Process-local live grants, one per sealed request object. A durable row or admission decision is never a grant. */
final class DispatchGate implements WireGate
{
    private \WeakMap $grants;
    public function __construct() { $this->grants = new \WeakMap; }
    /** The check runs once at the last local boundary and returns the send mode. It never receives the grant itself. */
    public function issue(AdmittedOperation $op, RequestContext $context, AdmittedRegistrarDriver $writer, \Closure $check): void
    { $this->grants[$op] = [$context, $writer, $check]; }
    public function revoke(AdmittedOperation $op): void
    { unset($this->grants[$op]); }
    /** Exact-object consume: the grant is gone before its check runs, so a failed check can never be retried. */
    public function consume(AdmittedOperation $op, RequestContext $context, AdmittedRegistrarDriver $writer): string
    {
        OperationClaims::outside(); $grant = $this->grants[$op] ?? null; unset($this->grants[$op]);
        if (!$grant || $grant[0] !== $context || $grant[1] !== $writer) { throw new \DomainException('No live renewal grant.'); }
        try { return ($grant[2])($op, $context, $writer); }
        catch (\Throwable $error) { throw new RenewalUnsent('Live renewal refused before the wire.', previous: $error); }
    }
    public function __debugInfo(): array { return []; }
    public function __serialize(): never { throw new \LogicException('Dispatch grants cannot be persisted.'); }
    public function __clone(): void { throw new \LogicException('Dispatch grants cannot be cloned.'); }
}
