<?php
// Synthetic cURL and DNS only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services {
    function dns_get_record($host, $type): array { return [['ip' => '8.8.8.8']]; }
    function curl_init($url) { return $url; }
    function curl_setopt_array($handle, $options): bool { $GLOBALS['rdapOptions'] = $options; return true; }
    function curl_exec($handle): bool {
        $GLOBALS['rdapCalls']++;
        $options = $GLOBALS['rdapOptions'];
        $options[CURLOPT_HEADERFUNCTION]($handle, "Retry-After: 120\r\n");
        $body = $GLOBALS['rdapBody'];
        if ($options[CURLOPT_WRITEFUNCTION]($handle, $body) !== strlen($body)) { return false; }
        return $GLOBALS['rdapErrno'] === CURLE_OK;
    }
    function curl_errno($handle): int { return $GLOBALS['rdapErrno']; }
    function curl_getinfo($handle, $key): int { return $GLOBALS['rdapStatus']; }
    function curl_close($handle): void {}
}
