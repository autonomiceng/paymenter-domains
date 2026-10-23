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
    $wire = null; $calls = []; $saved = null;
    try {
        $fake = static function ($command, $p) use ($out, $scenario, &$wire, &$calls, &$saved): TransportResponse {
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
                $reply = ['order' => in_array($scenario, ['registered', 'wrong-name', 'wrong-amount', 'duplicate'], true)
                    ? [['order_number' => '901', 'order_date' => gmdate('Y-m-d H:i:s'), 'total' => '8.95', 'method' => 'Account Funds']] : []];
            } elseif ($command === 'orderDetails') {
                $item = ['description' => ($scenario === 'wrong-name' ? 'contract-ote-123456789abc.com' : $wire->domain).' - registration',
                    'years_qty' => '1', 'price' => '8.95', 'subtotal' => $scenario === 'wrong-amount' ? '8.96' : '8.95', 'status' => 'Processed'];
                $reply = ['order_number' => '901', 'order_date' => gmdate('Y-m-d H:i:s'), 'total' => '8.95', 'method' => 'Account Funds',
                    'order_details' => $scenario === 'duplicate' ? [$item, $item] : [$item]];
            } elseif ($command === 'getDomainInfo') {
                $reply = in_array($scenario, ['registered', 'wrong-name', 'wrong-amount', 'duplicate', 'owned-no-order'], true)
                    ? ['created' => gmdate('Y-m-d'), 'expires' => gmdate('Y-m-d', strtotime('+1 year')), 'status' => 'Active',
                        'locked' => 'Yes', 'private' => 'No', 'auto_renew' => 'No']
                    : ['code' => $scenario === 'unknown-info' ? null : 200, 'detail' => 'synthetic'];
            } elseif ($command === 'checkRegisterAvailability') {
                $reply = $scenario === 'unavailable' ? ['unavailable' => [['domain' => $wire->domain]]]
                    : ['available' => [['domain' => $wire->domain, 'price' => '8.95', 'premium' => 0, 'duration' => 1]]];
            } else { throw new RuntimeException('Unexpected reconciliation command'); }
            if ($command === 'listOrders' && $scenario === 'timeout') { throw new RuntimeException('synthetic'); }
            if ($command === 'listOrders' && $scenario === 'unknown-orders') { $reply['code'] = null; }
            if ($command === 'listOrders' && $scenario === 'null-detail') { $reply['detail'] = null; }
            return new TransportResponse(200, json_encode(['request' => ['operation' => $command === 'listOrders' && $scenario === 'wrong-operation' ? 'getPrices' : $command],
                'reply' => $reply + ['code' => 300, 'detail' => 'synthetic']], JSON_THROW_ON_ERROR));
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
        }
        $binding = new AccountBinding('namesilo', 1, Environment::Test, 'synthetic');
        $category = $wire->purchaseAttempt()->reconcile($wire, new Client($binding, 'offline-secret-token', $wire), $binding, new SystemClock);
        same($category, $wire->receipt['reconciliation']['category'], 'Exactly one durable closed category');
        same(false, $wire->receipt['reconciliation']['adopted'], 'No native financial completion or adoption');
        $receipt = file_get_contents($out.'/receipt.json');
        foreach ([$wire->domain, '8.9500', '8.96', 'offline-secret-token', 'authorized_amount', 'dispatch_utc'] as $private) {
            expect(!str_contains($receipt, $private), 'Private attempt values absent from receipt');
        }
        same(1, count(array_filter($calls, static fn ($v) => $v === 'registerDomain')), 'Unknown never registers again');
        if (!str_starts_with($scenario, 'tamper-')) {
            expect(in_array('listOrders', $calls, true) && in_array('getDomainInfo', $calls, true), 'Unknown invokes real production reconciliation');
        } else { same(['registerDomain'], $calls, 'Changed evidence refused before reconciliation'); }
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
    'owned-no-order' => 'ambiguous', 'unknown-orders' => 'ambiguous', 'null-detail' => 'ambiguous', 'wrong-operation' => 'ambiguous', 'timeout' => 'ambiguous', 'tamper-name' => 'ambiguous', 'tamper-amount' => 'ambiguous'] as $scenario => $expected) {
    [$category] = $probe($scenario); same($expected, $category, 'Closed category for '.$scenario);
}
echo "Private purchase evidence: persistence, real reconciliation, categories and redaction passed\n";
