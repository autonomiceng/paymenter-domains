<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

use Paymenter\Extensions\Servers\Domains\Dns\DnsQuery;

/** A bounded nameserver list with explicit provenance. */
final readonly class NameserverEvidence
{
    public array $nameservers;

    private function __construct(array $nameservers, public string $state, public string $source, public string $at)
    {
        if (!in_array($state, ['observed', 'requested'], true)
            || !in_array($source, ['enom_getdns', 'namesilo_domain_info', 'public_dns_check', 'registration_request', 'transfer_request'], true)) {
            throw new \InvalidArgumentException('Invalid nameserver provenance.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $at);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d\TH:i:sP') !== $at) {
            throw new \InvalidArgumentException('Invalid nameserver observation time.');
        }
        if (($state === 'observed' && in_array($source, ['registration_request', 'transfer_request'], true))
            || ($state === 'requested' && !in_array($source, ['registration_request', 'transfer_request'], true))) {
            throw new \InvalidArgumentException('Nameserver source contradicts its state.');
        }
        if (!array_is_list($nameservers) || count($nameservers) < 1 || count($nameservers) > 13) {
            throw new \InvalidArgumentException('Invalid nameserver list.');
        }
        $hosts = [];
        foreach ($nameservers as $name) {
            if (!is_string($name)) { throw new \InvalidArgumentException('Invalid nameserver.'); }
            $hosts[] = DnsQuery::hostname($name);
        }
        if (count(array_unique($hosts)) !== count($hosts)) { throw new \InvalidArgumentException('Duplicate nameservers.'); }
        $this->nameservers = $hosts;
    }

    public static function observed(array $nameservers, string $source, \DateTimeImmutable $at): self
    { return new self($nameservers, 'observed', $source, $at->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM)); }

    public static function requested(array $nameservers, string $source, \DateTimeImmutable $at): self
    { return new self($nameservers, 'requested', $source, $at->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM)); }

    public static function fromArray(mixed $facts): ?self
    {
        if (!is_array($facts) || array_diff(array_keys($facts), ['nameservers', 'state', 'source', 'at'])
            || !is_array($facts['nameservers'] ?? null) || !is_string($facts['state'] ?? null)
            || !is_string($facts['source'] ?? null) || !is_string($facts['at'] ?? null)) { return null; }
        try { return new self($facts['nameservers'], $facts['state'], $facts['source'], $facts['at']); }
        catch (\Throwable) { return null; }
    }

    public function toArray(): array
    { return ['nameservers' => $this->nameservers, 'state' => $this->state, 'source' => $this->source, 'at' => $this->at]; }
}
