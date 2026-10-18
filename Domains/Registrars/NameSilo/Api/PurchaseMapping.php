<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;

final class PurchaseMapping implements \JsonSerializable
{
    private readonly \SensitiveParameterValue $payload;

    private function __construct(public readonly string $command, #[\SensitiveParameter] array $parameters)
    {
        // Opaque PHP storage keeps contact/auth values out of ordinary dumpers and casts.
        $this->payload = new \SensitiveParameterValue($parameters);
    }

    public static function register(string $domain, int $years, bool $privacy, string $contactId,
        array $nameservers, ?UsNexus $nexus = null): self
    {
        self::years($years);
        return new self('registerDomain', ['years' => $years] + self::purchase($domain, $privacy, $contactId, $nameservers, $nexus));
    }

    public static function transfer(string $domain, #[\SensitiveParameter] string $auth, bool $privacy,
        string $contactId, array $nameservers, ?UsNexus $nexus = null): self
    {
        if ($auth === '' || strlen($auth) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $auth)) {
            throw new \InvalidArgumentException('Invalid transfer authorization code.');
        }
        return new self('transferDomain', ['auth' => 'base64:'.base64_encode($auth)]
            + self::purchase($domain, $privacy, $contactId, $nameservers, $nexus));
    }

    /** Draft mapping only; no purchase mapping is accepted by the transport/client. */
    public static function renew(string $domain, int $years, bool $privacy): self
    {
        self::years($years);
        $parts = DomainName::parse($domain);
        if ($parts['tld'] === 'us' && $privacy) { throw new \InvalidArgumentException('Privacy unavailable for .us.'); }
        return new self('renewDomain', ['domain' => $parts['domain'], 'years' => $years, 'auto_renew' => 0, 'private' => (int) $privacy]);
    }

    private static function purchase(string $domain, bool $privacy, string $contactId, array $nameservers, ?UsNexus $nexus): array
    {
        $parts = DomainName::parse($domain);
        self::id($contactId);
        if (($parts['tld'] === 'us' && ($nexus === null || $privacy)) || ($parts['tld'] !== 'us' && $nexus !== null)) {
            throw new \InvalidArgumentException('Explicit .us eligibility and no privacy are required only for .us.');
        }
        if (!array_is_list($nameservers) || count($nameservers) < 2 || count($nameservers) > 13) {
            throw new \InvalidArgumentException('Supply between 2 and 13 nameservers.');
        }
        $parameters = ['domain' => $parts['domain'], 'contact_id' => $contactId, 'auto_renew' => 0, 'private' => (int) $privacy];
        $seen = [];
        foreach ($nameservers as $index => $host) {
            if (!is_string($host) || strlen($host) > 253 || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false
                || preg_match('/\.[0-9]+$/D', $host)) {
                throw new \InvalidArgumentException('Invalid nameserver.');
            }
            $host = strtolower($host);
            if ($host === $parts['domain'] || str_ends_with($host, '.'.$parts['domain'])) {
                throw new \InvalidArgumentException('Purchase nameservers must be outside the purchased domain.');
            }
            foreach (explode('.', $host) as $label) {
                if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) || str_starts_with($label, 'xn--')) {
                    throw new \InvalidArgumentException('Invalid nameserver.');
                }
            }
            if (isset($seen[$host])) { throw new \InvalidArgumentException('Duplicate nameserver.'); }
            $seen[$host] = true;
            $parameters['ns'.($index + 1)] = $host;
        }
        if ($nexus !== null) { $parameters += ['usnc' => $nexus->category, 'usap' => $nexus->purpose]; }
        return $parameters;
    }

    private static function years(int $years): void
    {
        if ($years < 1 || $years > 10) { throw new \InvalidArgumentException('Term must be between 1 and 10 years.'); }
    }

    private static function id(#[\SensitiveParameter] string $id): void
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $id)) { throw new \InvalidArgumentException('Invalid provider identifier.'); }
    }

    /** Sensitive wire mapping; never log or persist this array. */
    public function parameters(): array { return $this->payload->getValue(); }
    public function jsonSerialize(): array { return ['command' => $this->command]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { return $this->jsonSerialize(); }
    public function __unserialize(array $data): void { throw new \LogicException('Requests cannot be restored.'); }
    public function __toString(): string { return $this->command; }
}
