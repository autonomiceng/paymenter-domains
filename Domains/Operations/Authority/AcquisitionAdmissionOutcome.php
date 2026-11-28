<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

enum AcquisitionAdmissionOutcome
{
    case Applied;
    case Unavailable;
    case RetireForCredit;

    public function requireApplied(): void
    {
        if ($this === self::RetireForCredit) {
            throw new \DomainException('The wait for registrar funds has ended.');
        }
        if ($this !== self::Applied) {
            throw new \DomainException('Acquisition admission is unavailable.');
        }
    }
}
