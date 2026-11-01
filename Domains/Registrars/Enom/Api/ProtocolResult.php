<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;

/** Internal server-only reply. Raw values require explicit access and never enter diagnostics. */
final class ProtocolResult implements \JsonSerializable
{
    private static ?\WeakMap $facts = null;
    public function __construct(public readonly string $status, public readonly ?string $code = null,
        #[\SensitiveParameter] array $data = [], public readonly ?int $responseBytes = null)
    {
        if (!in_array($status, ['success', 'rejected', 'unknown'], true)
            || ($code !== null && !preg_match('/^(?:[0-9]{1,6}|[a-z][a-z_]{0,63})$/D', $code))) {
            throw new \InvalidArgumentException('Invalid protocol result.');
        }
        $facts = self::$facts ??= new \WeakMap;
        $facts[$this] = $status === 'success' ? $data : [];
    }
    public function value(string $path, mixed $default = null): mixed
    {
        $value = self::$facts[$this];
        foreach (explode('.', strtolower($path)) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) { return $default; }
            $value = $value[$key];
        }
        return $value;
    }
    public function jsonSerialize(): array { return ['status' => $this->status, 'code' => $this->code]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { throw new \LogicException('Protocol results cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Protocol results cannot be unserialized.'); }
    public function __clone(): void { throw new \LogicException('Protocol results cannot be cloned.'); }
}
