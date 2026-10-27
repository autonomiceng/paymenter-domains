<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data\Adoption;
use Paymenter\Extensions\Servers\Domains\Services\StaffReason;
final readonly class StaffContext
{
    public function __construct(public int $actorId, public string $reason, public bool $doNotRenew = false)
    {
        if ($actorId < 1 || trim($reason) === '' || preg_match('//u', $reason) !== 1 || mb_strlen($reason, 'UTF-8') > 500 || !StaffReason::singleLine($reason)) {
            throw new \InvalidArgumentException('A staff reason of at most 500 characters is required.');
        }
    }
}
