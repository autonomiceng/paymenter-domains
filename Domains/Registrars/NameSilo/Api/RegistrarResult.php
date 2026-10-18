<?php
// Adapted from Enom Api/RegistrarResult.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;

final class RegistrarResult implements \JsonSerializable
{
    private readonly \SensitiveParameterValue $payload;

    public function __construct(public readonly Outcome $outcome, public readonly ?int $code = null,
        #[\SensitiveParameter] array $data = [], public readonly ?RejectionScope $scope = null)
    {
        $this->payload = new \SensitiveParameterValue($data);
    }

    /** Only sanitized reply fields; no raw body, request, IP or provider diagnostic messages. */
    public function value(string $path, mixed $default = null): mixed
    {
        $value = $this->payload->getValue();
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) { return $default; }
            $value = $value[$key];
        }
        return $value;
    }
    public function jsonSerialize(): array { return ['outcome' => $this->outcome->value, 'code' => $this->code, 'scope' => $this->scope?->value]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { return $this->jsonSerialize(); }
    public function __unserialize(array $data): void { throw new \LogicException('Results cannot be restored.'); }
    public function __toString(): string { return $this->outcome->value; }
}
