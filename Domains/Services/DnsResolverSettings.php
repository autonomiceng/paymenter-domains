<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Dns\{DohDnsReader,DohEndpoint,DohTransport,DnsContext,DnsQuery,FallbackDnsReader,NativeDnsReader};
/** Extension-wide resolver configuration stays in native encrypted settings (including the first save). */
final class DnsResolverSettings
{
    public const KEY = 'domains_dns_resolver';
    public function __construct(private DohTransport $transport, private NativeDnsReader $native) {}
    public function configuration(): array
    {
        if (getenv('SSLKEYLOGFILE')) { return [null, 'TLS key logging is enabled. Disable SSLKEYLOGFILE before using DNS-over-HTTPS.']; }
        if (!DohTransport::available()) { return [null, 'The required cURL HTTP client is unavailable. Enable it before configuring DNS-over-HTTPS.']; }
        try {
            $rows = Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key', self::KEY)->get();
            if ($rows->isEmpty()) { return [null, 'Resolver settings are missing. Save the default or configure a resolver.']; }
            if ($rows->count() !== 1 || !$rows[0]->encrypted || $rows[0]->type !== 'string' || !is_string($rows[0]->value)) { throw new \UnexpectedValueException; }
            $data = json_decode($rows[0]->value, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_string($data['url'] ?? null) || !is_string($data['ip'] ?? null)) { throw new \UnexpectedValueException; }
            return [new DohEndpoint($data['url'], $data['ip']), null];
        } catch (\Throwable) { return [null, 'Resolver settings are invalid or cannot be decrypted. Save valid settings.']; }
    }
    public function save(string $url, string $ip): void
    {
        $endpoint = new DohEndpoint($url, $ip);
        DB::transaction(static function () use ($endpoint): void {
            $query = Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key', self::KEY);
            $rows = $query->lockForUpdate()->get();
            if ($rows->count() > 1) { throw new \DomainException('Duplicate resolver settings need administrator review.'); }
            $s = $rows->first() ?? new Setting(['settingable_type' => null, 'settingable_id' => null, 'key' => self::KEY]);
            $s->encrypted = true; $s->type = 'string';
            $s->value = json_encode(['url' => $endpoint->url, 'ip' => $endpoint->ip], JSON_THROW_ON_ERROR); $s->save();
        });
    }
    public function reader(): FallbackDnsReader
    {
        [$endpoint] = $this->configuration();
        return new FallbackDnsReader($endpoint === null ? null : new DohDnsReader($endpoint, $this->transport), $this->native);
    }
    /** Exactly one DoH query; a failed diagnostic never starts a blocking native resolver call. */
    public function check(): array
    {
        OperationClaims::outside(); [$endpoint, $reason] = $this->configuration();
        if ($endpoint === null) { return ['ok' => false, 'message' => "DNS-over-HTTPS not configured: using the server's resolver. ".$reason]; }
        $start = hrtime(true);
        $answer = (new DohDnsReader($endpoint, $this->transport))->read(new DnsQuery('example.com', 'A'), new DnsContext());
        $ms = (int) round((hrtime(true) - $start) / 1e6);
        if (str_starts_with($answer->code, 'transport_') || $answer->code === 'deadline') {
            $reason = match ($answer->code) {
                'transport_timeout' => 'timed out', 'transport_connect' => 'could not connect',
                'transport_tls' => 'TLS certificate or connection failed', 'transport_http_status' => 'HTTP response was not 200',
                'transport_too_large' => 'response exceeded 64 KiB', 'transport_malformed' => 'response was not valid DNS JSON',
                'deadline' => 'query deadline reached', default => 'HTTP client unavailable',
            };
            return ['ok' => false, 'message' => "DNS-over-HTTPS failed: using the server's resolver (".$reason.')'];
        }
        $identity = $endpoint->host.', '.$ms.' ms';
        if ($answer->state === 'rrset') { return ['ok' => true, 'message' => 'DNS-over-HTTPS working ('.$identity.')']; }
        if ($answer->state === 'negative') {
            return ['ok' => false, 'message' => 'DNS-over-HTTPS reachable ('.$identity.'), but the resolver says example.com has no address. Check the endpoint. The server’s resolver was not used.'];
        }
        $code = str_starts_with($answer->code, 'dns_rcode_') ? match ($answer->code) {
            'dns_rcode_1' => 'FORMERR', 'dns_rcode_2' => 'SERVFAIL', 'dns_rcode_4' => 'NOTIMP', 'dns_rcode_5' => 'REFUSED',
            default => 'RCODE '.substr($answer->code, 10),
        } : 'an unusable DNS answer';
        return ['ok' => false, 'message' => 'DNS-over-HTTPS reachable, but the resolver answered '.$code.' ('.$identity.'). Domain checks that receive this answer are recorded as unknown and do not use the server\'s resolver.'];
    }
}
