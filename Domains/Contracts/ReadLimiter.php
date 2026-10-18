<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, RequestOrigin};
/** Internal durable wire admission. No wait, retry or write authority. */
interface ReadLimiter
{
    /** @throws \Paymenter\Extensions\Servers\Domains\Services\ReadDeferred Local retryable refusal, definitely no wire sent. */
    public function acquire(AccountBinding $binding, RequestOrigin $origin): string;
    public function release(AccountBinding $binding, RequestOrigin $origin, string $token): void;
}
