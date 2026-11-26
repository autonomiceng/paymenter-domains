<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

/** One step of an operation. A renewal has a single renew step, sequence 1. */
final readonly class StepKey
{
    public function __construct(public string $type, public int $sequence)
    {
        if (!preg_match('/^[a-z_]{1,32}$/D', $type) || $sequence < 1) { throw new \InvalidArgumentException('Invalid operation step.'); }
    }

    public static function renew(): self { return new self('renew', 1); }
}
