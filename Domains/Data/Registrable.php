<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
final readonly class Registrable
{
    public function __construct(public string $hostname, public string $name, public string $psl_revision) {}
}
