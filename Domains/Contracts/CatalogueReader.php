<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;

use Paymenter\Extensions\Servers\Domains\Data\{CatalogueAcceptance, CatalogueFreshness, RequestContext};

interface CatalogueReader extends RegistrarDriver
{
    /** Accepted endings only; one batch per refresh. Missing/null entries retain prior state.
     * @return array<string, \Paymenter\Extensions\Servers\Domains\Data\CatalogueEntry|null>
     */
    public function catalogueBatch(CatalogueAcceptance $accepted, CatalogueFreshness $freshness, RequestContext $context): array;
}
