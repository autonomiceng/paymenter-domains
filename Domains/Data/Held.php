<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
final readonly class Held
{
    public function __construct(public string $safe_code, public string $psl_revision) {}
}
