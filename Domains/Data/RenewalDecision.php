<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
/** Short-lived server-only admission evidence. Billing24 and execution25 must revalidate independently. */
final readonly class RenewalDecision implements \JsonSerializable
{
    public function __construct(public RenewalCode $code, public RenewalStatus $status = RenewalStatus::Unknown,
        public ?Money $cost = null, public ?\DateTimeImmutable $validUntil = null, public ?string $staffAlert = null) {}
    public function allowed(): bool { return $this->code === RenewalCode::Admitted; }
    public function jsonSerialize(): array { return ['code' => $this->code->value, 'staff_alert' => $this->staffAlert]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): never { throw new \LogicException('Renewal admission is server-only.'); }
}
