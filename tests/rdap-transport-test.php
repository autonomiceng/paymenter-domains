<?php
// Synthetic cURL and DNS only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace {
    require __DIR__.'/bootstrap.php';
    require __DIR__.'/Support/rdap-wire.php';
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
        foreach (['', ' ', '{"errorCode":404', '[]', '{"errorCode":500}', '{"errorCode":"404"}'] as $rdapBody) {
            $rdapErrno = CURLE_RECV_ERROR; $rdapStatus = 404; $failure = null;
            try { $send->invoke(new Rdap(), 'https://rdap.example.com/domain/example.com', 8000, $pins); }
            catch (RuntimeException $e) { $failure = $e->getMessage(); }
            same('Lookup response is unavailable.', $failure, 'Failed 404 requires a complete RDAP error object on either path');
        }
        foreach (['{}', '{"errorCode":404}', '{"title":"Not found"}'] as $rdapBody) {
            same($rdapBody, $send->invoke(new Rdap(), 'https://rdap.example.com/domain/example.com', 8000, $pins)['body'], 'Complete RDAP 404 survives receive error');
        }
        $rdapErrno = CURLE_OK; $rdapStatus = 404; $rdapBody = '';
        same('', $send->invoke(new Rdap(), 'https://rdap.example.com/domain/example.com', 8000, $pins)['body'], 'Clean empty 404 keeps its meaning');
        $rdapErrno = CURLE_OK; $rdapStatus = 200; $rdapBody = '{"objectClassName":"domain"}';
        same(200, $send->invoke(new Rdap(), 'https://rdap.example.com/domain/example.com', 8000, $pins)['status'], 'Successful response retained');
        $rdapErrno = CURLE_RECV_ERROR; $rdapStatus = 404; $rdapBody = '{}';
    }
    same(32, $rdapCalls, 'Each bounded transport performs exactly one exchange');
    $bootstrap = ['services'=>[[['com'],['https://rdap.example.com']]]];
    $dns = new class implements \Paymenter\Extensions\Servers\Domains\Contracts\DnsReader {
        public function read(\Paymenter\Extensions\Servers\Domains\Dns\DnsQuery $q, \Paymenter\Extensions\Servers\Domains\Dns\DnsContext $c): \Paymenter\Extensions\Servers\Domains\Dns\DnsAnswer {
            $c->reserve();
            return $q->type === 'A' ? \Paymenter\Extensions\Servers\Domains\Dns\DnsAnswer::rrset($q, [['host'=>$q->name,'type'=>'A','class'=>'IN','ttl'=>60,'ip'=>'8.8.8.8']]) : \Paymenter\Extensions\Servers\Domains\Dns\DnsAnswer::negative($q);
        }
    };
    foreach ([[CURLE_RECV_ERROR,'',false], [CURLE_RECV_ERROR,'{"errorCode":404',false], [CURLE_RECV_ERROR,'{"errorCode":404}',true], [CURLE_OK,'',true], [CURLE_RECV_ERROR,'',false]] as [$rdapErrno,$rdapBody,$missing]) {
        $rdapStatus = 404; $rdap = new Rdap();
        (new ReflectionProperty(Rdap::class, 'bootstrap'))->setValue($rdap, $bootstrap);
        $result = $rdap->precheck('example.com');
        same($missing ? 'transfer_not_registered' : null, $result['code'], 'Transfer never infers absence from an incomplete failed reply');
        same($missing ? null : Rdap::UNCHECKED, $result['warning'], 'Unproven transfer remains advisory');
        $result = (new \Paymenter\Extensions\Servers\Domains\Services\PublicRegistryReader($dns, $bootstrap))->read('example.com', new \Paymenter\Extensions\Servers\Domains\Dns\RegistryContext());
        same($missing ? 'not_registered' : 'unknown', $result['result'], 'Public reader applies transport completion before shared missing rule');
        same($missing ? 'not_registered' : 'endpoint_failed', $result['reason'], 'Incomplete failed public replies remain retryable');
    }
    echo "Pinned and resolved RDAP completion, narrow 404 exception and transport failures passed.\n";
}
