<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;

use Paymenter\Extensions\Servers\Domains\Data\{DriverResult, Query, RequestContext};

/** Account/TLD advisory pricing, including follow-on renewal of a name not yet owned.
 * No availability, ownership, sale admission, persistence or spending authority. */
interface AcquisitionPriceReader
{
    public function acquisitionQuote(Query $query, RequestContext $context): DriverResult;
}
