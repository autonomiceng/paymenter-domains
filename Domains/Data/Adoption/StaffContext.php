<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data\Adoption;
final readonly class StaffContext
{
    public function __construct(public int $actorId, public string $reason, public bool $doNotRenew = false)
    {
        if ($actorId < 1 || trim($reason) === '' || preg_match('//u', $reason) !== 1 || mb_strlen($reason, 'UTF-8') > 500 || preg_match('/[\x00-\x1f\x7f\x{2028}\x{2029}]/u', $reason)) {
            throw new \InvalidArgumentException('A staff reason of at most 500 characters is required.');
        }
    }
}
