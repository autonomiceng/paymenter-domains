<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\RequestContext;

/** Adapters only observe; reads run outside database locks with the caller's bound budget. */
interface Feed
{
    /** Only evidenced exact terms. Omitted/malformed rows are absent, never disabled commands.
     * @return iterable<FeedObserved> */
    public function prices(RequestContext $context): iterable;
    public function facts(string $tld, RequestContext $context): FactsObserved;
}
