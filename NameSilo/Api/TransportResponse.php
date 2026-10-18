<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\NameSilo\Api;

final class TransportResponse implements \JsonSerializable
{
    private readonly \SensitiveParameterValue $payload;
    public function __construct(public readonly int $httpStatus, #[\SensitiveParameter] string $body)
    {
        $this->payload = new \SensitiveParameterValue($body);
    }
    public function body(): string { return $this->payload->getValue(); }
    public function jsonSerialize(): array { return ['http_status' => $this->httpStatus]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { return $this->jsonSerialize(); }
    public function __unserialize(array $data): void { throw new \LogicException('Responses cannot be restored.'); }
}
