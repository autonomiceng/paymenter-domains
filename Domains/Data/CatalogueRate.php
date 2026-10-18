<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

/** Server-only, verified per-year rate for this exact registration term (annual for other actions).
 * Null explicitly means provider-confirmed no price. Failed/unknown reads MUST throw instead.
 * An annual catalogue listing alone does not establish a multi-year registration rate.
 */
final readonly class CatalogueRate
{
    public function __construct(public string $action, public int $term, public ?string $perYear,
        public string $source, public \DateTimeImmutable $observedAt, public ?\DateTimeImmutable $freshUntil,
        public string $currency = 'USD', public bool $persistable = true)
    {
        if (!in_array($action, ['register', 'renew', 'transfer'], true) || $term < 1 || $term > 10
            || ($action !== 'register' && $term !== 1) || $currency !== 'USD'
            || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $source)
            || ($freshUntil !== null && $freshUntil < $observedAt)) {
            throw new \InvalidArgumentException('Invalid supported catalogue rate.');
        }
        if ($perYear !== null) { PricingRule::units($perYear); }
    }
}
