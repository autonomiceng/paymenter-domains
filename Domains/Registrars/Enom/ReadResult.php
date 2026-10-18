<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;

/** Server-only catalogue/contact facts; never pass this object to a customer presenter or job. */
final class ReadResult implements \JsonSerializable
{
    private static ?\WeakMap $facts = null;
    public function __construct(public readonly AccountBinding $binding, public readonly string $status,
        #[\SensitiveParameter] array $facts = [])
    {
        if (!in_array($status, ['success', 'unknown', 'no_price'], true)) { throw new \InvalidArgumentException('Invalid read status.'); }
        $store = self::$facts ??= new \WeakMap; $store[$this] = $status === 'success' ? $facts : [];
    }
    public function facts(): array { return self::$facts[$this]; }
    public function jsonSerialize(): array { return ['status' => $this->status]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { throw new \LogicException('Persist read facts through an encrypted adapter.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Read results cannot be unserialized.'); }
    public function __clone(): void { throw new \LogicException('Read results cannot be cloned.'); }
}
