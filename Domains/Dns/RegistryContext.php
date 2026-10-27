<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
/** A public read budget, independent of registrar/billing contexts. */
final class RegistryContext
{
    public readonly DnsContext $dns;
    private int $requests = 0;
    public function __construct(?callable $clock = null, private ?\Closure $admit = null, private ?\Closure $complete = null)
    { $this->dns = new DnsContext($clock); }
    public function available(): bool { return $this->dns->live() && $this->requests < 3; }
    public function reserve(string $url): bool
    {
        if (!$this->available()) { return false; }
        if ($this->admit !== null && !($this->admit)($url)) { throw new RegistryDeferred(); }
        $this->requests++; return true;
    }
    public function completed(string $url, ?array $response): void { if ($this->complete) { ($this->complete)($url, $response); } }
    public function used(): int { return $this->requests; }
}
