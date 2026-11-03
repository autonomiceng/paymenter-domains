<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;

use Paymenter\Extensions\Servers\Domains\Data\CanonicalName;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Dns;

/** Offline descriptors only. Deliberately not a RegistrarRequest: no sender accepts these writes. */
final class DnsMapping implements \JsonSerializable
{
    private readonly \SensitiveParameterValue $payload;
    private function __construct(public readonly string $command, #[\SensitiveParameter] array $parameters)
    { $this->payload = new \SensitiveParameterValue($parameters); }

    public static function listRecords(string $domain): self
    { return new self('dnsListRecords', ['domain' => CanonicalName::normalize($domain)]); }

    public static function add(string $domain, #[\SensitiveParameter] array $record): self
    {
        $parameters = self::record($domain, $record);
        return new self('dnsAddRecord', ['domain' => CanonicalName::normalize($domain), 'rrtype' => $record['type']] + $parameters);
    }

    /** Update never changes type. The sequence must express type changes as separately authorized deltas. */
    public static function update(string $domain, #[\SensitiveParameter] array $before, #[\SensitiveParameter] array $after): self
    {
        self::record($domain, $before);
        $id = Dns::id($before['record_id'] ?? null);
        if (($before['type'] ?? null) !== ($after['type'] ?? null)
            || (array_key_exists('record_id', $after) && $after['record_id'] !== $id)) {
            throw new \DomainException('DNS update requires the same record type and observed ID.');
        }
        return new self('dnsUpdateRecord', ['domain' => CanonicalName::normalize($domain), 'rrid' => $id] + self::record($domain, $after));
    }

    public static function delete(string $domain, #[\SensitiveParameter] string $recordId): self
    { return new self('dnsDeleteRecord', ['domain' => CanonicalName::normalize($domain), 'rrid' => Dns::id($recordId)]); }

    private static function record(string $domain, #[\SensitiveParameter] array $record): array
    {
        if (!in_array($record['type'] ?? null, Dns::STANDARD, true) || !is_string($record['name'] ?? null)
            || array_diff(array_keys($record), ['name', 'type', 'address', 'mx_pref', 'record_id', 'ttl'])) {
            throw new \DomainException('Unsupported DNS record.');
        }
        $parameters = ['rrhost' => Dns::host($domain, $record['name']),
            'rrvalue' => Dns::address($record['type'], $record['address'] ?? null),
            'rrttl' => Dns::integer($record['ttl'] ?? null, 2147483647)];
        if ($record['type'] === 'MX') { $parameters['rrdistance'] = Dns::integer($record['mx_pref'] ?? null, 65535); }
        return $parameters;
    }

    /** Sensitive wire mapping; never log or persist this array. */
    public function parameters(): array { return $this->payload->getValue(); }
    public function jsonSerialize(): array { return ['command' => $this->command]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { return $this->jsonSerialize(); }
    public function __unserialize(array $data): void { throw new \LogicException('DNS mappings cannot be restored.'); }
    public function __toString(): string { return $this->command; }
}
