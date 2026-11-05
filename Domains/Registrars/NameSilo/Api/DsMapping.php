<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;

use Paymenter\Extensions\Servers\Domains\Data\CanonicalName;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Ds, DsRegistryPolicy};

/** Offline descriptors, deliberately outside RegistrarRequest so the sender cannot execute them. */
final class DsMapping implements \JsonSerializable
{
    private readonly \SensitiveParameterValue $payload;
    private function __construct(public readonly string $command, #[\SensitiveParameter] array $parameters)
    { $this->payload = new \SensitiveParameterValue($parameters); }

    public static function listRecords(string $domain): self
    { return new self('dnsSecListRecords', ['domain' => CanonicalName::normalize($domain)]); }

    public static function add(string $domain, #[\SensitiveParameter] array $record, DsRegistryPolicy $policy): self
    { return new self('dnsSecAddRecord', self::record($domain, $record, $policy)); }

    /** Provider deletion identifies the full tuple, not a DNS record ID. */
    public static function delete(string $domain, #[\SensitiveParameter] array $record, DsRegistryPolicy $policy): self
    { return new self('dnsSecDeleteRecord', self::record($domain, $record, $policy)); }

    private static function record(string $domain, #[\SensitiveParameter] array $record, DsRegistryPolicy $policy): array
    {
        $domain = CanonicalName::normalize($domain);
        $tuple = Ds::tuple($record);
        if (!$policy->allows($domain, $tuple)) { throw new \DomainException('DS registry policy does not admit this tuple.'); }
        return ['domain' => $domain, 'digest' => $tuple['digest'], 'keyTag' => $tuple['key_tag'],
            'digestType' => $tuple['digest_type'], 'alg' => $tuple['algorithm']];
    }

    /** Server-side mapping only; never log or persist this array. */
    public function parameters(): array { return $this->payload->getValue(); }
    public function jsonSerialize(): array { return ['command' => $this->command]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { return $this->jsonSerialize(); }
    public function __unserialize(array $data): void { throw new \LogicException('DS mappings cannot be restored.'); }
    public function __toString(): string { return $this->command; }
}
