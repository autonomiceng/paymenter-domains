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
    public function __construct(private AccountBindings $bindings, private WriteFeatures $features, private WriteAcceptance $accepted) {}
    public function inspect(Operation $op, Domain $domain, RequestContext $context): DispatchReadiness
    {
        OperationClaims::outside(); $query = OperationClaims::query($op, $domain);
        $this->features->requireEnabled($query->action); ContactSetup::requireReady($op);
        $capability = $this->accepted->requireCurrent($context->binding, $query, $op);
        $reader = $this->bindings->reader($context->binding->serverId); $context->binding->requireMatch($reader->binding());
        $observed = $context->now();
        $availability = $reader->availability($query, $context); $quote = $reader->quote($query, $context); $balance = $reader->balance($context);
        foreach ([$availability, $quote, $balance] as $result) {
            $context->binding->requireMatch($result->binding);
            if ($result->disposition !== Disposition::Accepted) { throw new WriteHeld('write_acceptance_required'); }
        }
        $q = $quote->evidence()->quote; $funds = $balance->evidence()->balance; $facts = $availability->evidence();
        if (!$q || !$query->matches($q->query) || !$q->cost || $q->cost->currency !== 'USD' || !$funds || $funds->currency !== 'USD'
            || $facts->available !== true || $facts->premium !== false || $q->observedAt < $observed || $q->observedAt > $context->now()
            || !$q->validUntil || $q->validUntil <= $context->now() || strcmp(self::decimal($funds->amount), self::decimal($q->cost->amount)) < 0) {
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
    private static function decimal(string $amount): string
    {
        [$whole, $fraction] = explode('.', $amount); return str_pad($whole, 18, '0', STR_PAD_LEFT).str_pad($fraction, 6, '0');
    }
}
