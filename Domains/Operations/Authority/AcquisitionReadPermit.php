<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

/** Publication authority exists only for the exact live read at the locked decision time. */
final readonly class AcquisitionReadPermit
{
    private function __construct(public ReadToken $token, public \DateTimeImmutable $now) {}

    public static function issue(AcquisitionLifecycle $state, ReadToken $token, \DateTimeImmutable $now): ?self
    {
        $facts = $state->facts;
        return $state->permitsRead() && $facts->token == $token->attempt && $facts->readToken !== null
            && hash_equals($facts->readToken, $token->token) && $facts->readUntil == $token->until && $now < $token->until
            ? new self($token, $now) : null;
    }
}
