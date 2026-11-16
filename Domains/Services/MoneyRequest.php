<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** One money decision. An automatic decision names its retry work; a staff decision names none and is never queued. */
final readonly class MoneyRequest
{
    public function __construct(public Domain $seed, public ?string $kind = null, public ?int $subject = null,
        public ?int $staffId = null, public bool $released = false, public MoneyScope $scope = MoneyScope::Full) {}
}
