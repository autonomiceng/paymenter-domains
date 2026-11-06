<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,ManagementRequest,RequestContext};

/** Account-bound registrar reads only; supported discovery never means eligible mutation. */
interface ObservationReader
{
    public function bindsTo(AccountBinding $binding): bool;
    /** ['revision'=>string|null, 'families'=>array<string,'supported'|'unsupported'>]. */
    public function observationCapabilities(): array;
    /** Families retain complete/partial/unknown and original provider record representations. */
    public function discover(ManagementRequest $request, RequestContext $context, array $knownGlue = []): array;
}
