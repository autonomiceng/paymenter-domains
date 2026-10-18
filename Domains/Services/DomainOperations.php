<?php
// Adapted from Enom 444f31c Services/DomainOperations.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Contracts\{AdmittedRegistrarDriver, Clock, OperationPreflight, SealPreflight, WriteGate};
use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\Operation;

/** Dormant internal facade; no installed queue registration, completion or refund policy. */
final class DomainOperations
{
    private \WeakMap $grants;
    public function __construct(private readonly OperationClaims $claims, private readonly OperationPreflight $preflight,
        private readonly AdmittedRegistrarDriver $writer, private readonly Clock $clock) { $this->grants = new \WeakMap; }

    public function __clone(): void { throw new \LogicException('Dispatch scopes cannot be cloned.'); }
    public function __serialize(): array { throw new \LogicException('Dispatch scopes cannot be persisted.'); }
    public function __debugInfo(): array { return []; }

    /** Real bridges in slice20 MUST consume the live descriptor/context exactly once immediately before send. */
    public function consume(AdmittedOperation $operation, RequestContext $context, ?WriteAcceptance $acceptance = null): array
    {
        OperationClaims::outside();
        $grant = $this->grants[$operation] ?? null;
        if (!$grant || $grant['context'] !== $context || $grant['consumed'] || $this->clock->now() >= $context->deadline) {
            throw new \DomainException('No live engine dispatch authority.');
        }
        $this->grants[$operation] = [...$grant, 'consumed' => true];
        return $this->claims->verifySend($grant['sealed'], $operation, $context, $grant['ready'],
            $this->preflight instanceof SealPreflight ? $this->preflight : null, $acceptance);
    }

    public function execute(int $id): Operation
    {
        OperationClaims::outside();
        if ($this->writer instanceof WriteGate) {
            try {
                $this->writer->requireEnabled(\Paymenter\Extensions\Servers\Domains\Data\Action::Register);
                if (in_array(Operation::findOrFail($id)->state, ['queued', 'awaiting_payment'], true)) { $this->writer->reserve(); }
            }
            catch (WriteHeld $held) { return $this->claims->holdUnclaimed($id, $held->reason); }
        }
        $claim = $this->claims->claim($id);
        if (!$claim) { return Operation::findOrFail($id); }
        [$op, $domain, $binding] = $claim;
        try {
            $deadline = $op->started_at->modify('+'.ExecuteOperation::EXECUTION_SECONDS.' seconds');
            $context = new RequestContext($binding, RequestOrigin::Background, $deadline, new RequestBudget(ExecuteOperation::REQUEST_LIMIT), $this->clock);
            $ready = $this->preflight->inspect($op, $domain, $context);
            if ($context->budget->remaining() < 1) { throw new \DomainException('No dispatch request budget remains.'); }
            $sealed = $this->claims->seal($op, $ready, $this->preflight instanceof SealPreflight ? $this->preflight : null,
                $this->writer instanceof WriteGate ? $this->writer : null);
            if (!$sealed) { return $op->fresh(); }
            $op = $sealed;
            $dispatchDeadline = min($deadline, $ready->validUntil, $this->clock->now()->modify('+'.ExecuteOperation::REQUEST_SECONDS.' seconds'));
            $context = new RequestContext($binding, RequestOrigin::Background, $dispatchDeadline, $context->budget, $this->clock);
            $admitted = new AdmittedOperation($binding, $ready->query, $op->operation_key,
                $op->attempt['claim_key'], $op->authority_generation, 'operation:'.$op->id.':seal', OperationClaims::payloadDigest($op), $ready->cost);
            $this->grants[$admitted] = ['context' => $context, 'consumed' => false, 'sealed' => $op, 'ready' => $ready];
            try {
                $result = $this->writer->register($admitted, $context);
                if (!$this->grants[$admitted]['consumed']) { throw new \LogicException('Writer omitted engine authority consumption.'); }
                $binding->requireMatch($result->binding);
            } finally { unset($this->grants[$admitted]); }
            return $this->claims->finish($op, $result);
        } catch (WriteHeld $held) {
            return $this->claims->finish($op, holdReason: $held->reason);
        } catch (\Throwable) {
            // Never chain a provider/input exception or expose raw evidence through diagnostic traces.
            return $this->claims->finish($op);
        }
    }
}
