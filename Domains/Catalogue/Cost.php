<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

/** Exact-term USD observation. Annual input is valid only for the explicitly evidenced term. */
final readonly class Cost
{
    public ?string $total;
    public function __construct(public string $action, public int $term, public bool $enabled,
        ?string $amount, public CostUnit $sourceUnit, public string $source,
        public \DateTimeImmutable $observedAt, public \DateTimeImmutable $freshUntil,
        public string $currency = 'USD')
    {
        if (!in_array($action, ['register', 'renew', 'transfer'], true) || $term < 1 || $term > 10
            || ($action !== 'register' && $term !== 1) || $currency !== 'USD'
            || $enabled !== ($amount !== null) || $freshUntil <= $observedAt
            || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $source)) {
            throw new \InvalidArgumentException('Invalid exact-term cost observation.');
        }
        $total = $amount === null ? null : PricingRule::decimal(
            PricingRule::units($amount) * ($sourceUnit === CostUnit::Annual ? $term : 1), 4);
        if ($total !== null) { PricingRule::units($total); }
        $this->total = $total;
    }
    public function key(): string { return $this->action.':'.$this->term; }
    public function fresh(\DateTimeImmutable $now): bool { return $this->observedAt <= $now && $now < $this->freshUntil; }
}
