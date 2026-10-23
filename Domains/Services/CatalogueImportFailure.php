<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Staff import failure contains a closed cause, never an original exception or provider body. */
final class CatalogueImportFailure extends \DomainException
{
    public readonly string $failureCode;
    public function __construct(string $code)
    { $this->failureCode=ReadFailure::safe($code); parent::__construct('Catalogue import unavailable.'); }
}
