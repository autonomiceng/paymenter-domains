<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;

use Paymenter\Extensions\Servers\Domains\Registrars\Contract\ReadRefusal;

final class CurlTransport implements Transport
{
    /** @param \Closure(Environment, ReadCommand, string): \Closure $preflight Returns the final reservation check. */
    public function __construct(private readonly \Closure $preflight) {}

    public function send(Environment $environment, ReadCommand $command, #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] string $uid, #[\SensitiveParameter] string $token, int $timeoutMs): string
    {
        $handle = null;$deadline=hrtime(true)+max(0,$timeoutMs)*1000000;
        try {
            $command->validate($parameters);
            if ($uid === '' || $token === '' || (getenv('SSLKEYLOGFILE') !== false && getenv('SSLKEYLOGFILE') !== '') || $timeoutMs < 1 || $timeoutMs > Client::MAX_TIMEOUT_MS) {
                throw new \RuntimeException('Registrar transport unavailable.');
            }
            $body = '';
            $handle = curl_init();
            if ($handle === false) { throw new \RuntimeException('Registrar transport unavailable.'); }
            $options = [
                CURLOPT_URL => $environment === Environment::Live
                    ? 'https://reseller.enom.com/interface.asp' : 'https://resellertest.enom.com/interface.asp',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['Command' => $command->value, 'UID' => $uid,
                    'PW' => $token, 'ResponseType' => $command->responseType()] + $parameters, '', '&', PHP_QUERY_RFC3986),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', $command->bulkPricing() ? 'Accept: text/plain' : 'Accept: application/xml'],
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
            ];
            if (!curl_setopt_array($handle, $options)) { throw new \RuntimeException('Registrar transport incomplete.'); }
            $checkReservationDay = ($this->preflight)($environment, $command, $uid);
            $remaining = intdiv($deadline - hrtime(true), 1000000);
            if ($remaining < 1) { throw new \RuntimeException('Registrar transport incomplete.'); }
            $options[CURLOPT_CONNECTTIMEOUT_MS] = min(8000, $remaining);
            $options[CURLOPT_TIMEOUT_MS] = $remaining;
            if (!curl_setopt_array($handle, $options)) {
                throw new \RuntimeException('Registrar transport incomplete.');
            }
            $checkReservationDay();
            if (curl_exec($handle) === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new \RuntimeException('Registrar transport incomplete.');
            }
            return $body;
        } catch (ReadRefusal $refusal) {
            throw $refusal;
        } catch (\Throwable) {
            // Never retain credential-bearing cURL exceptions, callback arguments or their causes.
            throw new \RuntimeException('Registrar transport incomplete.');
        } finally {
            if ($handle !== null && $handle !== false) { curl_close($handle); }
        }
    }
}
