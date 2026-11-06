<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName};

/** Partial observations: empty is a no-op; the writer retains omissions and rejects older observations.
 * Costs never change fact timestamps. Disabled is positive provider evidence, not a failed read. */
final readonly class FeedObserved implements Command
{
    public array $costs;
    public function __construct(private AccountBinding $account, private string $ending, array $costs)
    {
        CanonicalName::parse('example.'.$ending, [$ending]);
        $indexed = [];
        foreach ($costs as $cost) {
            if (!$cost instanceof Cost || isset($indexed[$cost->key()])) {
                throw new \InvalidArgumentException('Invalid or duplicate feed observation.');
            }
            $indexed[$cost->key()] = $cost;
        }
        $this->costs = $indexed;
    }
    /** Annual support comes only from the registrar's normalized bulk row. Metadata keeps its own age.
     * The cost observation retains the source/time proving each annual term; it is checked separately at sale. */
    public function facts(Facts $metadata): Facts
    {
        $terms = $metadata->terms;
        foreach (['renew', 'transfer'] as $action) {
            $cost = $this->costs[$action.':1'] ?? null;
            if ($cost === null || !in_array($cost->source, ['enom_bulk_bucket_1', 'namesilo_account_annual'], true)) { continue; }
            $terms[$action] = $cost->enabled && $cost->total !== null
                && \Paymenter\Extensions\Servers\Domains\Services\PricingRule::units($cost->total) > 0 ? [1] : [];
        }
        return new Facts($terms, $metadata->registryFields, $metadata->renewalSafe, $metadata->requiresNameservers,
            $metadata->autoRenewOnly, $metadata->source, $metadata->observedAt, $metadata->freshUntil, $metadata->expansion);
    }
    public function binding(): AccountBinding { return $this->account; }
    public function tld(): string { return $this->ending; }
    public function __debugInfo(): array { return ['tld' => $this->ending]; }
    public function __serialize(): never { throw new \LogicException('Feed observations are server-only.'); }
}
