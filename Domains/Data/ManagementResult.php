<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
/** Confirmed state differs from accepted email request; neither claims message delivery. */
final readonly class ManagementResult implements \JsonSerializable
{
    private \SensitiveParameterValue $data;
    public function __construct(public bool $confirmed = false, public bool $emailAccepted = false,
        #[\SensitiveParameter] array $facts = [], public bool $refused = false)
    { $this->data = new \SensitiveParameterValue(json_decode(json_encode($facts, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)); }
    public function facts(): array { return $this->data->getValue(); }
    public function jsonSerialize(): array { return ['confirmed' => $this->confirmed, 'email_accepted' => $this->emailAccepted]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): never { throw new \LogicException('Management results are server-only.'); }
    public function __clone(): void { throw new \LogicException('Management results cannot be cloned.'); }
}
