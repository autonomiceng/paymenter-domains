<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

final readonly class AcquisitionContinuation
{
    public function __construct(
        public int $revision,
        public ?AcquisitionLane $lane,
        public ?int $dueAt,
        public ?\DateTimeImmutable $shortfallAt,
        public ?AcquisitionWindow $window,
    ) {}

    public function due(\DateTimeImmutable $now): bool
    {
        return $this->dueAt !== null && $this->dueAt <= $now->getTimestamp();
    }
}
