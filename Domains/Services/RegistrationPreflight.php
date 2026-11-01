<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\SealPreflight;
use Paymenter\Extensions\Servers\Domains\Data\{BalanceRetry, Capabilities, DispatchReadiness, Money, RequestContext};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Fresh bound reads outside locks; the local acceptance is checked again under seal locks. */
final readonly class RegistrationPreflight implements SealPreflight
{
    public function __construct(private AccountBindings $bindings, private WriteFeatures $features, private WriteAcceptance $accepted, private ?RegistrationProfiles $profiles = null) {}
    public function usesPolicy(WriteFeatures $features, WriteAcceptance $accepted, $transport): bool
    {
        return $features === $this->features && $accepted === $this->accepted
            && (!$transport instanceof \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Registration
                || ($this->profiles && $transport->usesProfiles($this->profiles) && $accepted->usesProfiles($this->profiles)));
    }
    public function inspect(Operation $op, Domain $domain, RequestContext $context): DispatchReadiness
    {
        OperationClaims::outside(); $query = OperationClaims::query($op, $domain);
        $this->features->requireEnabled($query->action); ContactSetup::requireReady($op);
        $privacy = $op->payload['privacy'] ?? false; $servers = $op->payload['nameservers'] ?? [];
        if (!is_bool($privacy)) { throw new WriteHeld('order_not_supported'); }
        if ($context->binding->registrarKey === 'enom') {
            if ($privacy) { throw new WriteHeld('order_not_supported'); }
            try { \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration::nameservers($servers); }
            catch (\InvalidArgumentException) { throw new WriteHeld('nameservers_required'); }
        } else {
            try { $nexus = RegistryInputs::nameSiloNexus($domain->tld, $op->payload['registry'] ?? null); }
            catch (\DomainException) { throw new WriteHeld('registry_details_unsupported'); }
            if ($nexus && $privacy) { throw new WriteHeld('registry_details_unsupported'); }
            try { \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\PurchaseMapping::register($query->name, $query->years, $privacy, '1', $servers, $nexus); }
            catch (\InvalidArgumentException) { throw new WriteHeld('nameservers_required'); }
        }
        $retryBalance=null;
        if(BalanceRetry::from($op)?->window){
            $probe=$context->binding->registrarKey==='enom'&&EnomTestRegistration::enabled($context->binding)
                ?$this->bindings->registrationPreflightReader($context->binding):$this->bindings->reader($context->binding->serverId);
            $context->binding->requireMatch($probe->binding());$retryBalance=BalanceRetries::probe($op,$probe,$context);
        }
        if ($this->profiles && $context->binding->registrarKey === 'namesilo') {
            $this->accepted->requireCurrent($context->binding, $query, $op, profile: false);
            $this->profiles->prepare($op, $context);
        }
        $capability = $this->accepted->requireCurrent($context->binding, $query, $op);
        $reader = $context->binding->registrarKey==='enom'&&EnomTestRegistration::enabled($context->binding)
            ?$this->bindings->registrationPreflightReader($context->binding)
            :$this->bindings->reader($context->binding->serverId);
        $context->binding->requireMatch($reader->binding());
        $observed = $context->now();
        $availability = $reader->availability($query, $context); $quote = $reader->quote($query, $context); $balance = $retryBalance??BalanceReads::read($reader, $context, 'preflight');
        WriteMoney::requireRead($context->binding, $availability, $quote, $balance);
        WriteMoney::requireAvailable($availability->evidence());
        $q = $quote->evidence()->quote; WriteMoney::requireFunds($q, $balance->evidence()->balance, $query, $observed, $context->now());
        // Neither a higher live quote nor a later sync can enlarge this order's frozen spending authority.
        $saved = $op->cost ?? [];
        try {
            if ($op->currency !== 'USD' || ($saved['currency'] ?? null) !== 'USD' || ($saved['unit'] ?? null) !== 'term_total'
                || !is_string($saved['estimated'] ?? null)) { throw new \InvalidArgumentException; }
            $ceiling = new Money($saved['estimated'], 'USD'); $total = new Money((string) $op->amount, 'USD');
        } catch (\InvalidArgumentException) { throw new WriteHeld('registrar_price_missing'); }
        if (WriteMoney::compare($q->cost->amount, $ceiling->amount) > 0 || WriteMoney::compare($q->cost->amount, $total->amount) > 0) {
            // We cannot absorb a higher cost by changing a paid customer's frozen order.
            throw new WriteHeld('registrar_price_increased');
        }
        return new DispatchReadiness($context->binding, $query,
            new Capabilities($context->binding, ['register' => $capability]),
            $q->cost, true, false, $observed, min($q->validUntil, $observed->modify('+120 seconds')), OperationClaims::payloadDigest($op));
    }
    public function requireAtSeal(Operation $op, Domain $domain, DispatchReadiness $ready): void
    {
        $this->features->requireEnabled($ready->query->action); ContactSetup::requireReady($op);
        $this->accepted->requireCurrent($ready->binding, OperationClaims::query($op, $domain), $op, true);
    }
}
