<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

/** Identity of one claimed attempt. It authorizes nothing until the writer finds it current under lock. */
final readonly class AttemptToken
{
    public function __construct(public int $operationId, public int $stepId, public int $attemptId, public int $ordinal,
        public string $claimKey) {}
}
