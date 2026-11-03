<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Money};
/** One operation's local physical-send admission; never replaces the engine's durable authority. */
interface WriteReservation
{
    public function bindsTo(AccountBinding $binding): bool;
    public function run(string $feature,int $operationId,\Closure $work): mixed;
    public function reserve(string $command,#[\SensitiveParameter] array $parameters,?Money $cost=null): void;
    public function unconsumed(int $operationId): bool;
}
