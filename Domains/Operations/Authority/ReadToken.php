<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

/** One registrar read claim on a sealed attempt. A successor's claim replaces it. */
final readonly class ReadToken
{
    public function __construct(public AttemptToken $attempt, public string $token, public \DateTimeImmutable $until,
        public bool $inheritedUnresolved) {}
}
