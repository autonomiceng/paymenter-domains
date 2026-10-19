<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** One HTTPS exchange. Resolved public addresses are pinned; no redirects, proxy or retry. */
final readonly class PslTransport
{
    public function __construct(private ?\Closure $exchange = null, private ?\Closure $resolve = null) {}
    public static function validUrl(string $url): bool
    {
        $p = parse_url($url);
        return strlen($url) <= 2048 && is_array($p) && ($p['scheme'] ?? null) === 'https'
            && !isset($p['user'])
            && !isset($p['query']) && !isset($p['fragment']) && (!isset($p['port']) || $p['port'] === 443)
            && isset($p['host']) && preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/iD', $p['host'])
            && str_contains($p['host'], '.') && !filter_var($p['host'], FILTER_VALIDATE_IP)
            && !preg_match('/(?:^|\.)(?:localhost|local|internal|invalid|test)\z/iD', $p['host'])
            && !preg_match('/[\x00-\x20\x7f\\\\]/', $url);
    }
    public function fetch(string $url, array $headers): array
    {
        if (!self::validUrl($url)) { throw new PslFailure('source_invalid'); }
        $r = $this->exchange ? ($this->exchange)($url, $headers) : $this->send($url, $headers);
        if (!is_array($r) || !is_int($r['status'] ?? null) || !is_string($r['body'] ?? null)) { throw new PslFailure('transport_failed'); }
        if (strlen($r['body']) > PslValidator::MAX_BYTES) { throw new PslFailure('too_large'); }
        if ($r['status'] >= 300 && $r['status'] !== 304 && $r['status'] < 400) { throw new PslFailure('redirect_refused'); }
        if (!in_array($r['status'], [200, 304], true)) { throw new PslFailure('http_failed'); }
        return $r;
    }
    public static function header(mixed $value): ?string
    { return is_string($value) && strlen($value) <= 512 && !preg_match('/[\x00-\x1f\x7f]/', $value) ? $value : null; }
    private function send(string $url, array $headers): array
    {
        $start = hrtime(true); $host = parse_url($url, PHP_URL_HOST);
        $records = $this->resolve ? ($this->resolve)($host) : dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) { throw new PslFailure('endpoint_unsafe'); }
            $addresses[] = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        }
        if (!$addresses || getenv('SSLKEYLOGFILE')) { throw new PslFailure('endpoint_unsafe'); }
        $remaining = 30000 - (int) ((hrtime(true) - $start) / 1000000);
        if ($remaining < 1) { throw new PslFailure('timeout'); }
        $body = ''; $metadata = []; $oversize = false; $headerBytes = 0;
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_VERBOSE => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT_MS => min(10000, $remaining), CURLOPT_TIMEOUT_MS => $remaining,
            CURLOPT_RESOLVE => [$host.':443:'.implode(',', $addresses)], CURLOPT_HTTPHEADER => ['Accept: text/plain', ...$headers],
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$metadata, &$headerBytes): int {
                $headerBytes += strlen($line); if ($headerBytes > 16384) { return 0; }
                if (str_starts_with($line, 'HTTP/')) { $metadata = []; }
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2); $key = strtolower(trim($key));
                    if (in_array($key, ['etag', 'last-modified'], true)) { $metadata[$key] = self::header(trim($value)); }
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$body, &$oversize): int {
                if (strlen($body) + strlen($chunk) > PslValidator::MAX_BYTES) { $oversize = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            }]);
        try {
            $ok = curl_exec($curl) !== false;
            if ($oversize) { throw new PslFailure('too_large'); }
            if (!$ok) { throw new PslFailure(curl_errno($curl) === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'transport_failed'); }
            return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $body, ...$metadata];
        } finally { curl_close($curl); }
    }
}
