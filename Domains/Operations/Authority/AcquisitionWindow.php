<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Services\TransferFollowup;

final readonly class AcquisitionWindow
{
    public function __construct(
        public int $revision,
        public string $origin,
        public ?string $reason,
        public int $authorityId,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public ?\DateTimeImmutable $inputCutoff,
        public string $expiryReason,
    ) {}

    public function effectiveEnd(): \DateTimeImmutable
    {
        return $this->inputCutoff === null ? $this->end : min($this->end, $this->inputCutoff);
    }

    public function fits(\DateTimeImmutable $now): bool
    {
        return $this->start <= $now
            && $now->modify('+'.ExecuteOperation::REQUEST_SECONDS.' seconds') <= $this->end
            && ($this->inputCutoff === null || $now < $this->inputCutoff);
    }

    public function blockedReason(\DateTimeImmutable $now): string
    {
        return $this->inputCutoff !== null && $now >= $this->inputCutoff
            ? 'transfer_setup_review_required' : $this->expiryReason;
    }

    public function seal(bool $transfer): array
    {
        return [
            'revision' => $this->revision,
            'origin' => $this->origin,
            'reason' => $this->reason,
            'authority_id' => $this->authorityId,
            'expiry_reason' => $this->expiryReason,
            'start' => OperationAttempts::at($this->start),
            'end' => OperationAttempts::at($this->effectiveEnd()),
            'input_cutoff' => $this->inputCutoff === null ? null : OperationAttempts::at($this->inputCutoff),
            ...($transfer ? [
                'poll_until' => OperationAttempts::at($this->start->modify('+7 days')),
                'followup_until' => OperationAttempts::at($this->start->modify('+'.(14 * TransferFollowup::DAY).' seconds')),
            ] : []),
        ];
    }

    public static function time(string $value): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        if (!$time || OperationAttempts::at($time) !== $value) { throw new \DomainException('Invalid acquisition window time.'); }
        return $time;
    }
}
