<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
use GuzzleHttp\{Client, HandlerStack};
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\{FnStream,Utils};
use GuzzleHttp\Exception\{RequestException,ConnectException};
/** Uses the shipped HTTP client with a pinned public address and bounded streaming response. */
final readonly class DohTransport
{
    public function __construct(private ?\Closure $handler = null) {}
    public static function available(): bool { return extension_loaded('curl') && class_exists(Client::class); }
    public function fetch(DohEndpoint $endpoint, DnsQuery $query, float $seconds): array
    {
        $ms = (int) floor(min(2.0, $seconds) * 1000);
        if ($ms < 1 || getenv('SSLKEYLOGFILE') || !self::available()) { throw new DohFailure('unavailable'); }
        $ip = str_contains($endpoint->ip, ':') ? '['.$endpoint->ip.']' : $endpoint->ip;
        $buffer = Utils::streamFor(''); $oversize = false;
        $sink = FnStream::decorate($buffer, ['write' => static function (string $bytes) use ($buffer, &$oversize): int {
            if ($buffer->getSize() + strlen($bytes) > 65536) { $oversize = true; return 0; }
            return $buffer->write($bytes);
        }]);
        $options = ['headers' => ['Accept' => 'application/dns-json'], 'query' => ['name' => $query->name.'.', 'type' => $query->type],
            'allow_redirects' => false, 'http_errors' => false, 'proxy' => '', 'verify' => true, 'protocols' => ['https'],
            'decode_content' => false, 'connect_timeout' => min(1000, $ms) / 1000, 'timeout' => $ms / 1000,
            'sink' => $sink, 'on_headers' => static function ($response): void {
                $length = $response->getHeaderLine('Content-Length');
                if ($length !== '' && (!ctype_digit($length) || (float) $length > 65536)) { throw new DohFailure('too_large'); }
            }, 'curl' => [CURLOPT_RESOLVE => [$endpoint->host.':443:'.$ip]]];
        try {
            // Force cURL: stream handlers cannot enforce public bootstrap pinning. No retries.
            $response = (new Client(['handler' => HandlerStack::create($this->handler ?? new CurlHandler())]))->get($endpoint->url, $options);
            if ($oversize || $response->getBody()->getSize() > 65536) { throw new DohFailure('too_large'); }
            if ($response->getStatusCode() !== 200) { throw new DohFailure('http_status'); }
            $response->getBody()->rewind(); $body = $response->getBody()->read(65537);
            if (strlen($body) > 65536) { throw new DohFailure('too_large'); }
            return ['status' => 200, 'body' => $body];
        } catch (DohFailure $e) { throw $e;
        } catch (\Throwable $e) {
            if ($oversize) { throw new DohFailure('too_large'); }
            if ($e->getPrevious() instanceof DohFailure) { throw $e->getPrevious(); }
            $errno = ($e instanceof RequestException || $e instanceof ConnectException) ? ($e->getHandlerContext()['errno'] ?? null) : null;
            throw new DohFailure(match ($errno) { 28 => 'timeout', 35, 51, 58, 60, 77, 83, 90, 91 => 'tls', default => 'connect' });
        } finally { $sink->close(); }
    }
}
