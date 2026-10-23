<?php
// Enom 444f31c owner/service presentation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** Customer data only. No models, binding, operation keys, provider IDs or financial evidence. */
final readonly class OwnedDomain
{
    public function __construct(public string $name, public string $status, public string $tone,
        public ?string $expiry, public string $renewal, public ?string $renewalPrice,
        public array $actions, public array $progress, public array $setup, public array $transfer,
        public array $management, public bool $testEnvironment, public bool $changesUnavailable = false, public ?string $renewalDue = null, public bool $setupPending = false, public ?string $registrationBadge = null, public bool $adopted = false, public ?string $plannedRenewalDue = null, public array $history = [], public array $renewalInvoice = [], public bool $originalDoNotRenew = false) {}
}
