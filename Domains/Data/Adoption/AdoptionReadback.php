<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data\Adoption;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, DateEvidence, Query};
/** Distinguishes completed evidence from refusal without labeling failures successful. */
final class AdoptionReadback implements \JsonSerializable
{
    private static ?\WeakMap $store = null;
    private function __construct(public readonly AccountBinding $binding, public readonly ?string $refusal, #[\SensitiveParameter] array $facts)
    { $store = self::$store ??= new \WeakMap; $store[$this] = ['refusal' => $refusal, ...$facts]; }
    public static function refused(AccountBinding $binding, string $reason): self
    {
        if ($reason === 'ready' || !isset(Review::LABELS[$reason])) { throw new \InvalidArgumentException('Invalid import refusal.'); }
        return new self($binding, $reason, []);
    }
    /** @internal Only the named reader supplies normalized, verified facts. */
    public static function verified(AccountBinding $binding, #[\SensitiveParameter] array $facts): self
    {
        Query::requireName($facts['name'] ?? '');
        if (!array_key_exists('provider_id', $facts) || ($facts['provider_id'] !== null
            && (!is_string($facts['provider_id']) || trim($facts['provider_id']) === '' || strlen($facts['provider_id']) > 255
                || preg_match('/[\x00-\x1f\x7f]/', $facts['provider_id'])))
            || !($facts['expiry'] ?? null) instanceof DateEvidence
            || !is_int($facts['command_set_version'] ?? null) || $facts['command_set_version'] < 1) {
            throw new \InvalidArgumentException('Incomplete import evidence.');
        }
        foreach (['auto_renew', 'ordinary', 'registrar_hold', 'redemption'] as $key) {
            if (!array_key_exists($key, $facts) || ($facts[$key] !== null && !is_bool($facts[$key]))) {
                throw new \InvalidArgumentException('Incomplete import state.');
            }
        }
        return new self($binding, null, array_intersect_key($facts, array_flip([
            'name', 'provider_id', 'expiry', 'auto_renew', 'ordinary', 'registrar_hold', 'redemption',
            'read_start', 'read_end', 'command_set_version', 'settings'])));
    }
    public function facts(): array { return self::$store[$this]; }
    public function jsonSerialize(): array { return ['status' => $this->refusal === null ? 'verified' : 'refused', 'refusal' => $this->refusal]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { throw new \LogicException('AdoptionReadback requires encrypted persistence.'); }
    public function __unserialize(array $data): void { throw new \LogicException('AdoptionReadback cannot be unserialized.'); }
    public function __clone(): void { throw new \LogicException('AdoptionReadback cannot be cloned.'); }
}
