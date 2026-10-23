<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
/** Two transport failures open this job's circuit; valid RCODEs never use native DNS. */
final readonly class FallbackDnsReader implements DnsReader
{
    public function __construct(private ?DohDnsReader $doh, private NativeDnsReader $native) {}
    public function read(DnsQuery $query, DnsContext $context): DnsAnswer
    {
        if ($context->remaining() < 0.1) { return DnsAnswer::unknown($query, 'deadline')->withSource($this->doh === null || $context->dohOpen() ? 'native_fallback' : 'doh'); }
        if ($this->doh === null || $context->dohOpen()) { return $this->native->read($query, $context)->withSource('native_fallback', $this->doh === null ? 'doh_unconfigured' : 'breaker_open'); }
        $answer = $this->doh->read($query, $context);
        if (!str_starts_with($answer->code, 'transport_')) { return $answer; }
        $context->dohFailed();
        if ($context->remaining() < 0.1) { return DnsAnswer::unknown($query, 'deadline')->withSource('doh'); }
        return $context->fallback(fn() => $this->native->read($query, $context))->withSource('native_fallback', substr($answer->code, 10));
    }
}
