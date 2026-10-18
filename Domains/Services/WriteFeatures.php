<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\Action;
/** One instance per immutable account; independent gates, all default off. */
final readonly class WriteFeatures
{
    public function __construct(public bool $registration = false, public bool $transfer = false,
        public bool $renewal = false, public bool $management = false) {}
    public function requireEnabled(Action $action): void
    {
        if (!match ($action) { Action::Register => $this->registration, Action::Transfer => $this->transfer, Action::Renew => $this->renewal }) {
            throw new WriteHeld('write_disabled');
        }
    }
    public function requireManagement(): void { if (!$this->management) { throw new WriteHeld('write_disabled'); } }
}
