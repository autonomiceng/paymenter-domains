<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;

use App\Models\Service;
use Paymenter\Extensions\Servers\Domains\Models\Domain;

/** Slice 24/39 must invoke inside native updating, with binding/catalogue then domain locks. */
interface NativeRepriceGuard
{
    public function assertSave(Service $service, Domain $domain, \DateTimeImmutable $now): void;
}
