<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Staff-only publication assessment, never spending or complimentary authority.
 * For renewals, false means retain prior retail and alert; never stop billing or dispatch. */
final readonly class MarginDecision
{
    public function __construct(public bool $publishable, public ?string $hold, public ?string $staffAlert) {}
}
