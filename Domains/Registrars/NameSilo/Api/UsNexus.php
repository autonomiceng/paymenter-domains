<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;

final readonly class UsNexus
{
    public function __construct(public string $category, public string $purpose)
    {
        if (!in_array($category, ['C11', 'C12', 'C21', 'C31', 'C32'], true)
            || !in_array($purpose, ['P1', 'P2', 'P3', 'P4', 'P5'], true)) {
            throw new \InvalidArgumentException('Invalid .us eligibility.');
        }
    }
}
