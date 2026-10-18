<?php
// Adapted from Enom Api/RegistrarClient.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\NameSilo\Api;

final class CurlTransport implements Transport
{
    public const BODY_LIMIT = 1048576;

    public function send(Environment $environment, RequestContext $context, RegistrarRequest $request,
        #[\SensitiveParameter] string $apiKey): TransportResponse
    {
        if (!function_exists('curl_init') || $apiKey === '' || getenv('SSLKEYLOGFILE')) {
            throw new \RuntimeException('Registrar transport unavailable.');
        }
        $body = '';
        $handle = null;
        try {
            $query = ['version' => 1, 'type' => 'json', 'key' => $apiKey] + $request->parameters();
            $url = $environment->origin().'/'.$context->value.'/'.$request->command.'?'
                .http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $handle = curl_init();
            if ($handle === false) { throw new \RuntimeException('Registrar transport unavailable.'); }
            $configured = curl_setopt_array($handle, [
                CURLOPT_URL => $url,
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_VERBOSE => false,
                CURLOPT_HEADER => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_LOW_SPEED_LIMIT => 32,
                CURLOPT_LOW_SPEED_TIME => 10,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($chunk) > self::BODY_LIMIT - strlen($body)) { return 0; }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (!$configured || curl_exec($handle) === false) {
                throw new \RuntimeException('Registrar transport incomplete.');
            }
            return new TransportResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body);
        } catch (\Throwable) {
            // cURL and injected exceptions may contain a credential-bearing URL; discard the cause.
            throw new \RuntimeException('Registrar transport incomplete.');
        } finally {
            if ($handle !== null && $handle !== false) { curl_close($handle); }
        }
    }
}
