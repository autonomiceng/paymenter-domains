<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
final readonly class DnsQuery
{
    public string $name;
    public function __construct(string $name, public string $type)
    {
        $this->name = self::hostname($name);
        if (!in_array($type, ['NS', 'A', 'AAAA', 'CNAME', 'MX'], true)) { throw new \InvalidArgumentException('Unsupported DNS type.'); }
    }
    public static function hostname(string $name): string
    {
        $name = strtolower($name);
        if (str_ends_with($name, '.')) { $name = substr($name, 0, -1); }
        if (strlen($name) > 253 || filter_var($name, FILTER_VALIDATE_IP) || preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/D', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid DNS hostname.');
        }
        return $name;
    }
    public function key(): string { return $this->name.'|'.$this->type; }
}
