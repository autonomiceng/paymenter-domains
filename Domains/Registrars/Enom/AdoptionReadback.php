<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
/** Distinguishes completed evidence from refusal without labeling failures successful. */
final class AdoptionReadback implements \JsonSerializable
{
    private static ?\WeakMap $store = null;
    private function __construct(public readonly AccountBinding $binding, public readonly ?string $refusal, #[\SensitiveParameter] array $facts)
    { $store = self::$store ??= new \WeakMap; $store[$this] = ['refusal' => $refusal, ...$facts]; }
    public static function refused(AccountBinding $binding, string $reason): self
    { return new self($binding, $reason, []); }
    /** @internal Only the named reader supplies normalized, verified facts. */
    public static function verified(AccountBinding $binding, #[\SensitiveParameter] array $facts): self
    { return new self($binding, null, $facts); }
    public function facts(): array { return self::$store[$this]; }
    public function jsonSerialize(): array { return ['status' => $this->refusal === null ? 'verified' : 'refused', 'refusal' => $this->refusal]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { throw new \LogicException('Readback requires encrypted persistence.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Readback cannot be unserialized.'); }
    public function __clone(): void { throw new \LogicException('Readback cannot be cloned.'); }
}
