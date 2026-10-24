<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
foreach (['Shapes', 'ParserDiagnostics', 'PurchaseAttempt', 'GuardedTransport'] as $class) { require __DIR__.'/contract/'.$class.'.php'; }
use OteContract\GuardedTransport;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, Money, SystemClock};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Client;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Outcome, PurchaseMapping, ResponseParser, TransportResponse};

$probe = static function (string $scenario): array {
    $out = sys_get_temp_dir().'/ote-evidence-'.bin2hex(random_bytes(6)); mkdir($out, 0700);
    $wire = null; $calls = []; $saved = null; $infoReads = 0;
    $domainInfo = json_decode(file_get_contents(__DIR__.'/fixtures/synthetic-success.json'), true, 24, JSON_THROW_ON_ERROR)['getDomainInfo']['reply'];
    try {
        $fake = static function ($command, $p) use ($out, $scenario, $domainInfo, &$wire, &$calls, &$saved, &$infoReads): TransportResponse {
            $calls[] = $command;
            if ($command === 'registerDomain') {
                expect(is_file($out.'/attempt-private.json'), 'Private evidence precedes dispatch');
                clearstatcache(); same(0600, fileperms($out.'/attempt-private.json') & 0777, 'Private attempt mode');
                $saved = json_decode(file_get_contents($out.'/attempt-private.json'), true);
                same(['name', 'authorized_amount', 'term', 'route', 'dispatch_utc', 'command'], array_keys($saved), 'Private file contains only approved facts');
                same($p['domain'], $saved['name'], 'Exact dispatched identity retained');
                same('8.9500', $saved['authorized_amount'], 'Exact authorized decimal retained');
                same(1, $saved['term'], 'Exact term retained'); same('/apibatch', $saved['route'], 'Dispatch route retained');
                expect(abs(time() - (new DateTimeImmutable($saved['dispatch_utc']))->getTimestamp()) <= 1, 'Actual dispatch UTC retained');
                $reply = ['code' => null, 'detail' => null];
            } elseif ($command === 'listOrders') {
                $reply = ['order' => (in_array($scenario, ['registered', 'wrong-name', 'wrong-amount', 'duplicate'], true) || str_starts_with($scenario, 'registered-'))
                    ? [['order_number' => '901', 'order_date' => gmdate('Y-m-d H:i:s'), 'total' => '8.95', 'method' => 'Account Funds']] : []];
            } elseif ($command === 'orderDetails') {
                $item = ['description' => ($scenario === 'wrong-name' ? 'contract-ote-123456789abc.com' : $wire->domain).' - registration',
                    'years_qty' => '1', 'price' => '8.95', 'subtotal' => $scenario === 'wrong-amount' ? '8.96' : '8.95', 'status' => 'Processed'];
                $reply = ['order_number' => '901', 'order_date' => gmdate('Y-m-d H:i:s'), 'total' => '8.95', 'method' => 'Account Funds',
                    'order_details' => $scenario === 'duplicate' ? [$item, $item] : [$item]];
            } elseif ($command === 'getDomainInfo') {
                ++$infoReads;
                $reply = in_array($scenario, ['registered', 'wrong-name', 'wrong-amount', 'duplicate', 'owned-no-order'], true) || str_starts_with($scenario, 'registered-')
                    ? array_replace($domainInfo, ['created' => gmdate('Y-m-d'), 'expires' => gmdate('Y-m-d', strtotime('+1 year'))])
                    : ['code' => $scenario === 'unknown-info' ? null : 200, 'detail' => 'synthetic'];
            } elseif ($command === 'checkRegisterAvailability') {
                $reply = $scenario === 'unavailable' ? ['unavailable' => [['domain' => $wire->domain]]]
                    : ['available' => [['domain' => $wire->domain, 'price' => '8.95', 'premium' => 0, 'duration' => 1]]];
            } else { throw new RuntimeException('Unexpected reconciliation command'); }
            if ($command === 'listOrders' && $scenario === 'timeout') { throw new RuntimeException('synthetic'); }
            if ($command === 'listOrders' && $scenario === 'unknown-orders') { $reply['code'] = null; }
            if ($command === 'listOrders' && $scenario === 'null-detail') { $reply['detail'] = null; }
            $body = ['request' => ['operation' => $command === 'listOrders' && $scenario === 'wrong-operation' ? 'getPrices' : $command,
                'ip' => '192.0.2.1'], 'reply' => $reply + ['code' => 300, 'detail' => 'synthetic']];
            if ($command === 'getDomainInfo') {
                if (str_starts_with($scenario, 'registered-') || str_starts_with($scenario, 'absence-') || str_starts_with($scenario, 'stale-')) {
                    same($body['reply']['code'] === 200 ? ['reply.code_not_success'] : [],
                        \OteContract\Shapes::check($command, $body), 'Baseline fixture passes its exact shape contract');
                }
                if ($scenario === 'absence-string-code') { $body['reply']['code'] = '200'; }
                if ($scenario === 'absence-missing-ip' || $scenario === 'registered-missing-ip'
                    || ($scenario === 'stale-shape' && $infoReads > 1)) { unset($body['request']['ip']); }
                if ($scenario === 'absence-null-ip') { $body['request']['ip'] = null; }
                if ($scenario === 'absence-null-detail') { $body['reply']['detail'] = null; }
                if ($scenario === 'absence-wrong-operation') { $body['request']['operation'] = 'getPrices'; }
                if ($scenario === 'absence-request-code') { $body['reply']['code'] = 109; }
                if ($scenario === 'absence-unknown-code') { $body['reply']['code'] = 400; }
                if ($scenario === 'absence-order-claim') { $body['reply']['order_number'] = null; }
                if ($scenario === 'absence-domain-claim') { $body['reply']['domain'] = $wire->domain; }
                if ($scenario === 'absence-extra-reply') { $body['reply']['internal'] = null; }
                if ($scenario === 'absence-extra-request') { $body['request']['internal'] = null; }
                if ($scenario === 'absence-extra-envelope') { $body['internal'] = null; }
                if ($scenario === 'registered-shape') { unset($body['reply']['traffic_type']); }
                if ($scenario === 'stale-throw' && $infoReads > 1) { throw new RuntimeException('Synthetic read failure'); }
                if ($scenario === 'absence-decode') { return new TransportResponse(200, '{'); }
                if ($scenario === 'absence-http') { return new TransportResponse(503, json_encode($body, JSON_THROW_ON_ERROR)); }
            }
            if ($scenario === 'registered-history-shape' && $command === 'listOrders') { unset($body['request']['ip']); }
            if ($scenario === 'registered-detail-shape' && $command === 'orderDetails') { unset($body['request']['ip']); }
            if ($scenario === 'availability-shape' && $command === 'checkRegisterAvailability') { unset($body['request']['ip']); }
            return new TransportResponse(200, json_encode($body, JSON_THROW_ON_ERROR));
        };
        $wire = new GuardedTransport('offline-secret-token', true, $out, $fake, static fn () => 21, 10);
        $wire->purchaseAuthorization(static fn () => new Money('8.9500', 'USD'));
        $body = $wire->raw('https://ote.namesilo.com/apibatch', 'registerDomain', ['domain' => $wire->domain, 'years' => 1, 'auto_renew' => 0, 'private' => 0]);
        $mapping = PurchaseMapping::register($wire->domain, 1, false, '101', ['ns1.namesilo.com', 'ns2.namesilo.com']);
        same(Outcome::Unknown, (new ResponseParser)->parse($body, $mapping, 'offline-secret-token')->outcome, 'Null purchase stays Unknown');
        if (str_starts_with($scenario, 'tamper-')) {
            $field = $scenario === 'tamper-name' ? 'name' : 'authorized_amount';
            $bad = $saved; $bad[$field] = $field === 'name' ? 'contract-ote-123456789abc.com' : '0.01';
            file_put_contents($out.'/attempt-private.json', json_encode($bad));
        } elseif ($scenario === 'mode-0644') {
            chmod($out.'/attempt-private.json', 0644); clearstatcache();
            same(0644, fileperms($out.'/attempt-private.json') & 0777, 'Invalid private evidence mode installed');
        } elseif ($scenario === 'symlink') {
            rename($out.'/attempt-private.json', $out.'/attempt-target.json');
            chmod($out.'/attempt-target.json', 0600);
            symlink($out.'/attempt-target.json', $out.'/attempt-private.json');
            expect(is_link($out.'/attempt-private.json'), 'Private evidence symlink installed');
        }
        $binding = new AccountBinding('namesilo', 1, Environment::Test, 'synthetic');
        $category = $wire->purchaseAttempt()->reconcile($wire, new Client($binding, 'offline-secret-token', $wire), $binding, new SystemClock);
        if (str_starts_with($scenario, 'stale-')) {
            same('not_registered', $category, 'First reconciliation establishes valid absence');
            same([], $wire->latest('getDomainInfo'), 'Valid absence never enters the success cache');
            $offset = $wire->receipt['request_count'];
            $category = $wire->purchaseAttempt()->reconcile($wire, new Client($binding, 'offline-secret-token', $wire), $binding, new SystemClock);
            $current = array_slice($wire->receipt['requests'], $offset);
            expect(count($current) >= 2, 'Second reconciliation attempts fresh history and domain reads');
            same([], array_values(array_filter($current, static fn ($r) => ($r['absence_validated'] ?? false) === true)),
                'Current reconciliation cannot reuse a prior absence marker');
        }
        $infos = array_values(array_filter($wire->receipt['requests'], static fn ($r) => $r['command'] === 'getDomainInfo'));
        $info = $infos ? $infos[array_key_last($infos)] : [];
        if (in_array($scenario, ['absent', 'absence-string-code'], true)) {
            same(true, $info['absence_validated'] ?? false, 'Documented code 200 validates absence');
            same(false, $info['read_validated'] ?? false, 'Absence is not a successful read');
            same([], $wire->latest('getDomainInfo'), 'Rejected absence never enters latest');
        }
        if (str_starts_with($scenario, 'absence-') && $scenario !== 'absence-string-code') {
            same(false, $info['absence_validated'] ?? false, 'Malformed absence has no validated marker: '.$scenario);
        }
        if (in_array($scenario, ['registered-shape', 'registered-missing-ip'], true)) {
            same('Success', $info['read_outcome'], 'Malformed success still passes the production parser');
            same(false, $info['read_validated'], 'Structural refusal invalidates production facts');
        }
        if ($scenario === 'availability-shape') {
            $availability = $wire->receipt['requests'][array_key_last($wire->receipt['requests'])];
            same('checkRegisterAvailability', $availability['command'], 'Fresh availability is the final read');
            same('Success', $availability['read_outcome'], 'Malformed availability passes the production parser');
            same(false, $availability['read_validated'], 'Malformed availability is not validated evidence');
            same([], $wire->latest('checkRegisterAvailability'), 'Malformed availability is absent from the success cache');
        }
        same($category, $wire->receipt['reconciliation']['category'], 'Exactly one durable closed category');
        same(false, $wire->receipt['reconciliation']['adopted'], 'No native financial completion or adoption');
        $receipt = file_get_contents($out.'/receipt.json');
        foreach ([$wire->domain, '8.9500', '8.96', 'offline-secret-token', 'authorized_amount', 'dispatch_utc'] as $private) {
            expect(!str_contains($receipt, $private), 'Private attempt values absent from receipt');
        }
        same(1, count(array_filter($calls, static fn ($v) => $v === 'registerDomain')), 'Unknown never registers again');
        if (!str_starts_with($scenario, 'tamper-') && !in_array($scenario, ['mode-0644', 'symlink'], true)) {
            expect(in_array('listOrders', $calls, true) && in_array('getDomainInfo', $calls, true), 'Unknown invokes real production reconciliation');
        } else { same(['registerDomain'], $calls, 'Invalid private evidence refused before reconciliation'); }
        foreach ([$wire->domain, '8.9500'] as $leak) {
            $wire->receipt['leak'] = $leak; $refused = false;
            try { $wire->save(); } catch (Throwable $error) {
                same(RuntimeException::class, $error::class, 'Private evidence refusal class');
                same('Private attempt receipt leak refused.',
                    $error->getMessage(), 'Private evidence refusal must name its guard: '.$scenario.'; received '.$error->getMessage());
                $refused = true;
            }
            expect($refused, 'Private name or amount injection into receipt refused');
            unset($wire->receipt['leak']);
        }
        return [$category, $calls];
    } finally {
        foreach (glob($out.'/*') as $file) { unlink($file); } rmdir($out);
    }
};
foreach (['registered' => 'registered_in_account', 'absent' => 'not_registered', 'wrong-name' => 'ambiguous',
    'wrong-amount' => 'ambiguous', 'duplicate' => 'ambiguous', 'unknown-info' => 'ambiguous', 'unavailable' => 'ambiguous',
    'owned-no-order' => 'ambiguous', 'unknown-orders' => 'ambiguous', 'null-detail' => 'ambiguous', 'wrong-operation' => 'ambiguous', 'timeout' => 'ambiguous', 'tamper-name' => 'ambiguous', 'tamper-amount' => 'ambiguous',
    'mode-0644' => 'ambiguous', 'symlink' => 'ambiguous', 'absence-string-code' => 'not_registered',
    'absence-missing-ip' => 'ambiguous', 'absence-null-ip' => 'ambiguous', 'absence-null-detail' => 'ambiguous',
    'absence-wrong-operation' => 'ambiguous', 'absence-request-code' => 'ambiguous', 'absence-unknown-code' => 'ambiguous',
    'absence-order-claim' => 'ambiguous', 'absence-domain-claim' => 'ambiguous', 'absence-extra-reply' => 'ambiguous',
    'absence-extra-request' => 'ambiguous', 'absence-extra-envelope' => 'ambiguous', 'absence-decode' => 'ambiguous',
    'absence-http' => 'ambiguous', 'registered-shape' => 'ambiguous', 'registered-missing-ip' => 'ambiguous',
    'registered-history-shape' => 'ambiguous', 'registered-detail-shape' => 'ambiguous', 'availability-shape' => 'ambiguous',
    'stale-shape' => 'ambiguous', 'stale-throw' => 'ambiguous'] as $scenario => $expected) {
    [$category] = $probe($scenario); same($expected, $category, 'Closed category for '.$scenario);
}

foreach ([false, true] as $invalid) {
    $out=sys_get_temp_dir().'/ote-auth-'.bin2hex(random_bytes(6));mkdir($out,0700);$calls=[];
    try {
        $fake=static function($command,$p)use(&$calls):TransportResponse{$calls[]=$command;return new TransportResponse(200,json_encode(['request'=>['operation'=>$command],'reply'=>['code'=>300,'detail'=>'synthetic']]));};
        $wire=new GuardedTransport('offline-secret-token',true,$out,$fake,static fn()=>21,10);
        if($invalid){$wire->purchaseAuthorization(static fn()=>new stdClass);}
        $refused=false;
        try{$wire->raw('https://ote.namesilo.com/apibatch','registerDomain',['domain'=>$wire->domain,'years'=>1,'auto_renew'=>0,'private'=>0]);}
        catch(RuntimeException){$refused=true;}
        expect($refused,'Missing or invalid purchase authorization refuses registration');
        same([], $calls, 'Unauthorized registration never reaches fake transport');
        expect(!file_exists($out.'/attempt-private.json'), 'Unauthorized registration writes no private evidence');
    } finally {foreach(glob($out.'/*') as $file){unlink($file);}rmdir($out);}
}

$out=sys_get_temp_dir().'/ote-renewal-evidence-'.bin2hex(random_bytes(6));mkdir($out,0700);$calls=[];
try {
    $fake=static function($command,$p)use(&$calls):TransportResponse{$calls[]=$command;return new TransportResponse(200,json_encode(['request'=>['operation'=>$command],'reply'=>['code'=>300,'detail'=>'synthetic']]));};
    $wire=new GuardedTransport('offline-secret-token',true,$out,$fake,static fn()=>21,10);
    $wire->purchaseAuthorization(static fn()=>new Money('8.9500','USD'));
    $wire->raw('https://ote.namesilo.com/apibatch','registerDomain',['domain'=>$wire->domain,'years'=>1,'auto_renew'=>0,'private'=>0]);
    $wire->authorizeRenewal(true);$wire->renewalBefore('2027-10-22');
    $wire->raw('https://ote.namesilo.com/apibatch','renewDomain',['domain'=>$wire->domain,'years'=>1,'auto_renew'=>0]);
    same(['registerDomain','renewDomain'],$calls,'Authorized renewal follows the recorded registration');
    $registration=json_decode(file_get_contents($out.'/registration-attempt-private.json'),true,16,JSON_THROW_ON_ERROR);
    $renewal=json_decode(file_get_contents($out.'/attempt-private.json'),true,16,JSON_THROW_ON_ERROR);
    same('registerDomain',$registration['command'],'First purchase is archived as registration evidence');
    same('renewDomain',$renewal['command'],'Current private evidence is the renewal');
    same('2027-10-22',$renewal['before_expiry'],'Renewal evidence retains the prior expiry');
} finally {foreach(glob($out.'/*') as $file){unlink($file);}rmdir($out);}
echo "Private purchase evidence: persistence, real reconciliation, categories and redaction passed\n";
