<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\RequestContext;

/** Buffer inside the headroom scope and reject a rotation before exposing any observation. */
final class CredentialFeed implements PricedFeed
{
    public function __construct(private readonly PricedFeed $feed, private readonly \Closure $current, private readonly \Closure $scope) {}
    public function priceReads(): int { return $this->feed->priceReads(); }
    public function factsReads(): int { return $this->feed->factsReads(); }
    public function prices(RequestContext $context): iterable
    {
        ($this->current)();
        $rows = ($this->scope)(function () use ($context): array {
            $rows = []; foreach ($this->feed->prices($context) as $row) { $rows[] = $row; } return $rows;
        });
        ($this->current)(); return $rows;
    }
    public function facts(string $tld, RequestContext $context): FactsObserved
    {
        ($this->current)();
        $facts = ($this->scope)(fn () => $this->feed->facts($tld, $context));
        ($this->current)(); return $facts;
    }
}
