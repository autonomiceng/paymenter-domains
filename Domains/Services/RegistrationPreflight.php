<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\SealPreflight;
use Paymenter\Extensions\Servers\Domains\Data\{Capabilities, DispatchReadiness, Disposition, RequestContext};
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
        if (!is_bool($privacy)) { throw new WriteHeld('write_acceptance_required'); }
        if ($context->binding->registrarKey === 'enom') {
            if ($privacy) { throw new WriteHeld('write_acceptance_required'); }
            try { \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration::nameservers($servers); }
            catch (\InvalidArgumentException) { throw new WriteHeld('nameservers_required'); }
        } else {
            try { \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\PurchaseMapping::register($query->name, $query->years, $privacy, '1', $servers); }
            catch (\InvalidArgumentException) { throw new WriteHeld('nameservers_required'); }
        }
        if ($this->profiles && $context->binding->registrarKey === 'namesilo') {
            $this->accepted->requireCurrent($context->binding, $query, $op, profile: false);
            $this->profiles->prepare($op, $context);
        }
        $capability = $this->accepted->requireCurrent($context->binding, $query, $op);
        $reader = $this->bindings->reader($context->binding->serverId); $context->binding->requireMatch($reader->binding());
        $observed = $context->now();
        $availability = $reader->availability($query, $context); $quote = $reader->quote($query, $context); $balance = $reader->balance($context);
        foreach ([$availability, $quote, $balance] as $result) {
            $context->binding->requireMatch($result->binding);
            if ($result->disposition !== Disposition::Accepted) { throw new WriteHeld('write_acceptance_required'); }
        }
        $q = $quote->evidence()->quote; $funds = $balance->evidence()->balance; $facts = $availability->evidence();
        if (!WriteMoney::cost($q, $query, $observed, $context->now())
            || $facts->available !== true || $facts->premium !== false || !WriteMoney::sufficient($funds, $q->cost)) {
            throw new WriteHeld('write_acceptance_required');
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
