<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

/** Server-only batch/locked loader result. No model, database or provider dependency.
 * publishedIntent is the selling intent the published revision was made under, when only account defaults changed since. */
final readonly class Snapshot
{
    public array $costs;
    public function __construct(public FeedObserved $feed, public ?Facts $facts, public Intent $intent,
        public Revision $revision, public ?Revision $publishedRevision, public array $retail,
        public ?AccountBinding $seller, public array $sellerActions = ['register', 'transfer'],
        public bool $accountReady = false, public bool $hidden = false, public ?string $retainedAnnualRetail = null,
        public ?Intent $publishedIntent = null)
    {
        foreach ($retail as $key => $price) {
            if (!isset($feed->costs[$key]) || !is_string($price)) { throw new \InvalidArgumentException('Invalid published price.'); }
            PricingRule::units($price, 2);
        }
        if (array_diff($sellerActions, ['register', 'transfer']) !== []) { throw new \InvalidArgumentException('Invalid seller actions.'); }
        if ($retainedAnnualRetail !== null) { PricingRule::units($retainedAnnualRetail, 2); }
        $this->costs = $feed->costs;
    }
    public function __debugInfo(): array { return ['tld' => $this->feed->tld()]; }
    public function __serialize(): never { throw new \LogicException('Offer snapshots are server-only.'); }
}
