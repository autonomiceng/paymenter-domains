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
namespace {
    require __DIR__.'/bootstrap.php';
    use Paymenter\Extensions\Servers\Domains\Services\Rdap;
    $rdapCalls = 0; $rdapBody = '{}'; $rdapErrno = CURLE_RECV_ERROR; $rdapStatus = 404;
    $send = new ReflectionMethod(Rdap::class, 'send');
    // Both paths must use the same narrow completed-status rule, without network access.
    foreach ([['8.8.8.8'], null] as $pins) {
        $reply = $send->invoke(new Rdap(), 'https://rdap.example.com/domain/example.com', 8000, $pins);
        same(['status'=>404, 'body'=>'{}', 'retry_after'=>'120'], $reply, 'Close-delimited 404 survives unclean close on either path');
        same(['rdap.example.com:443:8.8.8.8'], $rdapOptions[CURLOPT_RESOLVE], 'TLS hostname keeps public pins');
        foreach ([[CURLE_RECV_ERROR,200], [CURLE_OPERATION_TIMEDOUT,404], [CURLE_PARTIAL_FILE,404], [CURLE_SSL_CONNECT_ERROR,404]] as [$rdapErrno,$rdapStatus]) {
            $refused = false;
            try { $send->invoke(new Rdap(), 'https://rdap.example.com/domain/example.com', 8000, $pins); }
            catch (RuntimeException) { $refused = true; }
            expect($refused, 'Unproven completion stays unknown on either path');
        }
        $rdapErrno = CURLE_OK; $rdapStatus = 200; $rdapBody = '{"objectClassName":"domain"}';
        same(200, $send->invoke(new Rdap(), 'https://rdap.example.com/domain/example.com', 8000, $pins)['status'], 'Successful response retained');
        $rdapErrno = CURLE_RECV_ERROR; $rdapStatus = 404; $rdapBody = '{}';
    }
    same(12, $rdapCalls, 'Each bounded transport performs exactly one exchange');
    echo "Pinned and resolved RDAP completion, narrow 404 exception and transport failures passed.\n";
}
