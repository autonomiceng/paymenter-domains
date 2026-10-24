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
    /** [endpoint, reason, forced]: forced means the server cannot use a custom resolver; otherwise a null endpoint is a Missing saved resolver. */
    public function configuration(): array
    {
        if (getenv('SSLKEYLOGFILE')) { return [null, 'Turn off SSLKEYLOGFILE on the server to use a custom resolver.', true]; }
        if (!DohTransport::available()) { return [null, 'Install the PHP cURL extension to use a custom resolver.', true]; }
        try {
            $rows = Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key', self::KEY)->get();
            if ($rows->isEmpty()) { return [null, "No resolver set. Checks use the server's resolver.", false]; }
            if ($rows->count() !== 1 || !$rows[0]->encrypted || $rows[0]->type !== 'string' || !is_string($rows[0]->value)) { throw new \UnexpectedValueException; }
            $data = json_decode($rows[0]->value, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_string($data['url'] ?? null) || !is_string($data['ip'] ?? null)) { throw new \UnexpectedValueException; }
            return [new DohEndpoint($data['url'], $data['ip']), null, false];
        } catch (\Throwable) { return [null, "Saved settings can't be read. Checks use the server's resolver.", false]; }
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
    /** Exactly one DoH query; a failed test never starts a blocking native resolver call. */
    public function check(): array
    {
        OperationClaims::outside(); [$endpoint, $reason] = $this->configuration();
        if ($endpoint === null) { return ['ok' => false, 'message' => $reason]; }
        $start = hrtime(true);
        $answer = (new DohDnsReader($endpoint, $this->transport))->read(new DnsQuery('example.com', 'A'), new DnsContext());
        $ms = (int) round((hrtime(true) - $start) / 1e6);
        if ($answer->code === 'deadline') { return ['ok' => false, 'message' => 'Resolver failed: timed out.']; }
        if (str_starts_with($answer->code, 'transport_')) {
            $reason = match ($answer->code) {
                'transport_timeout' => 'timed out', 'transport_connect' => "couldn't connect", 'transport_tls' => 'TLS error',
                'transport_http_status' => 'HTTP error', 'transport_too_large' => 'response too large', 'transport_malformed' => 'not a DNS answer',
                default => 'PHP cURL missing',
            };
            return ['ok' => false, 'message' => 'Resolver failed: '.$reason.'.'];
        }
        if ($answer->state === 'rrset') { return ['ok' => true, 'message' => 'Resolver works ('.$endpoint->host.', '.$ms.' ms)']; }
        if ($answer->state === 'negative') { return ['ok' => false, 'message' => 'Resolver works, but returned no address for example.com.']; }
        $code = str_starts_with($answer->code, 'dns_rcode_') ? match ($answer->code) {
            'dns_rcode_1' => 'FORMERR', 'dns_rcode_2' => 'SERVFAIL', 'dns_rcode_4' => 'NOTIMP', 'dns_rcode_5' => 'REFUSED',
            default => 'error '.substr($answer->code, 10),
        } : 'an unusable answer';
        return ['ok' => false, 'message' => 'Resolver returned '.$code.'.'];
    }
}
