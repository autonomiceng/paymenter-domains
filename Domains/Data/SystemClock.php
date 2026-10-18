<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable { return new \DateTimeImmutable('now'); }
}
