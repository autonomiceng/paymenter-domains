<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;

use Paymenter\Extensions\Servers\Domains\Data\Environment;

final class CurlTransport implements Transport
{
    public function send(Environment $environment, ReadCommand $command, #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] string $uid, #[\SensitiveParameter] string $token, int $timeoutMs): string
    {
        $handle = null;
        try {
            if ($uid === '' || $token === '' || (getenv('SSLKEYLOGFILE') !== false && getenv('SSLKEYLOGFILE') !== '') || $timeoutMs < 1 || $timeoutMs > 25000) {
                throw new \RuntimeException('Registrar transport unavailable.');
            }
            $body = '';
            $handle = curl_init();
            if ($handle === false) { throw new \RuntimeException('Registrar transport unavailable.'); }
            $configured = curl_setopt_array($handle, [
                CURLOPT_URL => $environment === Environment::Live
                    ? 'https://reseller.enom.com/interface.asp' : 'https://resellertest.enom.com/interface.asp',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['Command' => $command->value, 'UID' => $uid,
                    'PW' => $token, 'ResponseType' => 'XML'] + $parameters, '', '&', PHP_QUERY_RFC3986),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/xml'],
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_VERBOSE => false, CURLOPT_HEADER => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS => min(8000, $timeoutMs), CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_LOW_SPEED_LIMIT => 32, CURLOPT_LOW_SPEED_TIME => 10,
                CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $command): int {
                    if (strlen($chunk) > $command->bodyLimit() - strlen($body)) { return 0; }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (!$configured || curl_exec($handle) === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new \RuntimeException('Registrar transport incomplete.');
            }
            return $body;
        } catch (\Throwable) {
            // Never retain credential-bearing cURL exceptions, callback arguments or their causes.
            throw new \RuntimeException('Registrar transport incomplete.');
        } finally {
            if ($handle !== null && $handle !== false) { curl_close($handle); }
        }
    }
}
