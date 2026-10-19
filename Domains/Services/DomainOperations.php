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
    public function consume(AdmittedOperation $operation, RequestContext $context, WriteAcceptance $acceptance, ?WriteFeatures $features = null, ?\Paymenter\Extensions\Servers\Domains\Contracts\RegistrationTransport $transport = null): array
    {
        OperationClaims::outside();
        $grant = $this->grants[$operation] ?? null;
        if (!$grant || $grant['context'] !== $context || $grant['consumed'] || $this->clock->now() >= $context->deadline) {
            throw new \DomainException('No live engine dispatch authority.');
        }
        if ($transport instanceof \Paymenter\Extensions\Servers\Domains\Contracts\RegistrationProtocol) {
            if (!$this->writer instanceof RegistrationBridge || !$features) { throw new \DomainException('Production dispatch requires a bound bridge.'); }
            $this->writer->requireComposition($this->preflight, $features, $acceptance, $transport);
        }
        $this->grants[$operation] = [...$grant, 'consumed' => true];
        return $this->claims->verifySend($grant['sealed'], $operation, $context, $grant['ready'],
            $this->preflight instanceof SealPreflight ? $this->preflight : null, $acceptance);
    }

    public function execute(int $id): Operation
    {
        return $this->clock instanceof \Paymenter\Extensions\Servers\Domains\Data\SystemClock
            ? $this->clock->withinExecution(fn () => $this->executeEntered($id)) : $this->executeEntered($id);
    }

    private function executeEntered(int $id): Operation
    {
        $entry = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        OperationClaims::outside();
        $route = Operation::findOrFail($id);
        if ($route->action === 'transfer' && !TransferInputs::validCode($route->payload['epp'] ?? null)) { return $route; }
        if ($this->writer instanceof RegistrationBridge) { $this->writer->checkComposition($this->preflight); }
        if ($this->writer instanceof WriteGate) {
            try {
                $this->writer->requireEnabled(\Paymenter\Extensions\Servers\Domains\Data\Action::from($route->action));
                // Missing/invalid owner setup is not an acquisition attempt or a rate reservation.
                if ($this->writer instanceof RegistrationBridge && !AcquisitionSetup::ready($route)) { return $route; }
                if (in_array($route->state, ['queued', 'awaiting_payment', 'awaiting_code'], true)) { $this->writer->reserve(); }
            }
            catch (WriteHeld $held) { return $held->reason==='write_rate_limited'?$route:$this->claims->holdUnclaimed($id, $held->reason); }
        }
        $claim = $this->claims->claim($id);
        if (!$claim) { return Operation::findOrFail($id); }
        [$op, $domain, $binding] = $claim;
        $result = null;
        try {
            $deadline = $entry->modify('+'.(ExecuteOperation::EXECUTION_SECONDS - ExecuteOperation::FINISH_SECONDS).' seconds');
            $context = new RequestContext($binding, RequestOrigin::Background, $deadline, new RequestBudget($op->action === 'transfer' ? 12 : ExecuteOperation::REQUEST_LIMIT), $this->clock, Runtime::limiter());
            $ready = $this->preflight->inspect($op, $domain, $context);
            if ($this->preflight instanceof TransferPreflight) { $ready = $this->preflight->inspect($op, $domain, $context); }
            if ($context->budget->remaining() < 1) { throw new \DomainException('No dispatch request budget remains.'); }
            $dispatchDeadline = min($deadline, $ready->validUntil, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('+'.ExecuteOperation::REQUEST_SECONDS.' seconds'));
            $context = new RequestContext($binding, RequestOrigin::Background, $dispatchDeadline, $context->budget, $this->clock, Runtime::limiter());
            return $context->withReservedWire(function()use(&$op,$ready,$context,$binding,&$result,$entry):Operation {
            $sealed = $this->claims->seal($op, $ready, $entry, $this->preflight instanceof SealPreflight ? $this->preflight : null,
                $this->writer instanceof WriteGate ? $this->writer : null,$context);
            if (!$sealed) { return $op->fresh(); }
            $op = $sealed;
            $admitted = new AdmittedOperation($binding, $ready->query, $op->operation_key,
                $op->attempt['claim_key'], $op->authority_generation, 'operation:'.$op->id.':seal', OperationClaims::payloadDigest($op), $ready->cost);
            $this->grants[$admitted] = ['context' => $context, 'consumed' => false, 'sealed' => $op, 'ready' => $ready];
            try {
                $response = $op->action === 'transfer' ? $this->writer->transferIn($admitted, $context) : $this->writer->register($admitted, $context);
                if (!$this->grants[$admitted]['consumed']) { throw new \LogicException('Writer omitted engine authority consumption.'); }
                $binding->requireMatch($response->binding); $result = $response;
            } finally { unset($this->grants[$admitted]); }
            return $this->claims->finish($op, $result);
            });
        } catch (ReadDeferred) {
            return $this->claims->defer($op);
        } catch (WriteHeld $held) {
            return $this->claims->finish($op, $result, holdReason: $held->reason);
        } catch (\Throwable) {
            // Never chain a provider/input exception or expose raw evidence through diagnostic traces.
            return $this->claims->finish($op, $result); // One completion retry retains the original result; never resend.
        }
    }
}
