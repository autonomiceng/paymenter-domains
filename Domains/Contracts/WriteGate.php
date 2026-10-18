<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\{Action, DispatchReadiness};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
interface WriteGate
{
    public function requireEnabled(Action $action): void;
    /** Nonblocking account rate slot, outside database locks and before claiming. */
    public function reserve(): void;
    /** Local checks under the seal locks; never provider I/O. */
    public function requireAtSeal(Operation $op, Domain $domain, DispatchReadiness $ready): void;
}
