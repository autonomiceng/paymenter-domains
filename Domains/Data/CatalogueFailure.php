<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
use Paymenter\Extensions\Servers\Domains\Services\ReadFailure;
/** Failed ending read retains prior evidence and carries only a closed local cause. */
final readonly class CatalogueFailure
{
    public string $code;
    public function __construct(string $code) { $this->code=ReadFailure::safe($code); }
}
