<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
/** Server-only desired state. Possession confers no dispatch authority. */
final readonly class ManagementRequest
{
    private \SensitiveParameterValue $data;
    public function __construct(public AccountBinding $binding, public string $key, public string $name,
        public string $action, #[\SensitiveParameter] array $payload, public ?string $providerId = null, public ?string $orderId = null, public ?string $profileId = null, public ?int $domainId = null)
    { $this->data = new \SensitiveParameterValue(json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)); }
    public function payload(): array { return $this->data->getValue(); }
    public function __debugInfo(): array { return ['action' => $this->action]; }
    public function __serialize(): never { throw new \LogicException('Management requests are server-only.'); }
    public function __clone(): void { throw new \LogicException('Management requests cannot be cloned.'); }
}
