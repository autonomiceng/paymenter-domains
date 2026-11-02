<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Data\Adoption\AdoptionReadback;
/** Bound, read-only import evidence. Construction performs no provider I/O. */
interface AdoptionSource
{
    public function binding(): AccountBinding;
    public function read(string $name, string $suffix): AdoptionReadback;
}
