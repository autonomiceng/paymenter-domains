<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
foreach (['Shapes', 'ParserDiagnostics', 'GuardedTransport', 'ReconciliationProbe'] as $class) { require __DIR__.'/contract/'.$class.'.php'; }
use OteContract\{GuardedTransport, ReconciliationProbe};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Outcome, PurchaseMapping, ResponseParser, TransportResponse};

$prior = ['checks' => ['purchase_unresolved' => true], 'requests' => [
    ['command' => 'registerDomain', 'utc' => '2026-10-19T00:38:49+00:00']]];
[$from, $to] = ReconciliationProbe::window($prior);
same(['2026-10-19 00:38:00', '2026-10-19 00:40:00'], [$from, $to], 'Original attempt fixes the literal window');
$header = ['order_number' => '901', 'order_date' => '2026-10-19 00:38:49', 'method' => 'Account Funds', 'total' => '8.95'];
$item = ['description' => 'contract-ote-123456789abc.com - registration', 'years_qty' => '1', 'price' => '8.95', 'subtotal' => '8.95', 'status' => 'Processed'];
$detail = $header + ['order_details' => [$item]];
expect(ReconciliationProbe::consistent($header, $detail, $from, $to), 'Consistent synthetic registration is diagnostic evidence');
$refuse = static function (callable $fn, string $expected): void {
    try { $fn(); } catch (Throwable $error) {
        same(RuntimeException::class, $error::class, 'Reconciliation refusal class');
        same($expected, $error->getMessage(), 'Reconciliation refusal must name its guard'); return;
    }
    throw new RuntimeException('Expected refusal: '.$expected);
};
foreach ([[], ['checks' => ['purchase_unresolved' => false]] + $prior,
    ['requests' => array_merge($prior['requests'], $prior['requests'])] + $prior,
    ['requests' => [['command' => 'renewDomain', 'utc' => $prior['requests'][0]['utc']]]] + $prior,
    ['requests' => [['command' => 'registerDomain', 'utc' => '2026-02-30T00:38:49+00:00']]] + $prior,
    ['requests' => [['command' => 'registerDomain', 'utc' => '2026-10-19T00:38:49-07:00']]] + $prior] as $index => $bad) {
    $expected = match ($index) {
        0, 1 => 'Unresolved attempt receipt required.', 2, 3 => 'Single original registration required.',
        4 => 'Invalid original timestamp.', 5 => 'Original UTC timestamp required.',
    };
    $refuse(fn () => ReconciliationProbe::window($bad), $expected);
}
foreach (['2026-10-19 00:37:59', '2026-10-19 00:40:00', '2026-02-30 00:38:49', 'bad'] as $stamp) {
    $h = array_replace($header, ['order_date' => $stamp]); $d = array_replace($detail, ['order_date' => $stamp]);
    same(false, ReconciliationProbe::consistent($h, $d, $from, $to), 'Wrong literal window/date never correlates');
}
foreach (['total' => '8.96', 'order_number' => '902', 'order_date' => '2026-10-19 00:39:00'] as $field => $value) {
    same(false, ReconciliationProbe::consistent($header, array_replace($detail, [$field => $value]), $from, $to), 'Header and detail must agree');
}
foreach ([['description' => 'unrelated-placeholder.com - registration'], ['description' => 'contract-ote-123456789abc.com - renewal'],
    ['description' => 'contract-ote-123456789abc.org - registration'], ['years_qty' => '2'], ['status' => 'Credited'],
    ['price' => '8.96'], ['subtotal' => '8.96'], ['subtotal' => '0.00'], ['subtotal' => false], ['subtotal' => '8.950'],
    ['credited_amount' => null], ['credited_date' => null]] as $bad) {
    same(false, ReconciliationProbe::consistent($header, array_replace($detail, ['order_details' => [array_replace($item, $bad)]]), $from, $to), 'Malformed, unrelated or contradictory order excluded');
}
same(false, ReconciliationProbe::consistent($header, array_replace($detail, ['order_details' => [$item, $item]]), $from, $to), 'Multiple items never count as one purchase');
$probe = static function (array $headers, array $details, int $cap = 15, bool $failedOrders = false, ?array $inventory = null, ?string $inventoryFailure = null) use ($prior, $refuse): array {
    $output = sys_get_temp_dir().'/ote-reconciliation-'.bin2hex(random_bytes(6)); mkdir($output, 0700);
    try {
        $commands = [];
        $fake = static function ($command, $p) use ($headers, $details, $failedOrders, $inventory, $inventoryFailure, $output, &$commands): TransportResponse {
            $commands[] = $command;
            $durable = json_decode(file_get_contents($output.'/receipt.json'), true);
            same(true, $durable['checks']['purchase_unresolved'] ?? null, 'Unresolved marker persisted before every request');
            same('ambiguous', $durable['reconciliation']['category'] ?? null, 'Ambiguity persisted before every request');
            if ($command === 'listDomains' && $inventoryFailure === 'throw') { throw new RuntimeException('synthetic timeout'); }
            $reply = match ($command) {
                'listOrders' => ['order' => $headers],
                'orderDetails' => $details[$p['order_number']],
                'listDomains' => $inventory ?? ['domains' => null, 'pager' => ['page' => 1, 'pageSize' => 10, 'total' => 0]],
                default => throw new RuntimeException('Read-only reconciliation violated'),
            };
            return new TransportResponse($command === 'listDomains' && $inventoryFailure === 'http' ? 503 : 200, json_encode(['request' => ['operation' => $command, 'ip' => '192.0.2.1'],
                'reply' => ['code' => $failedOrders && $command === 'listOrders' ? 400 : 300, 'detail' => 'synthetic'] + $reply], JSON_PRESERVE_ZERO_FRACTION));
        };
        $wire = new GuardedTransport('offline-secret-token', false, $output, $fake, static fn () => 21, $cap, true);
        $refuse(fn () => new GuardedTransport('offline-secret-token', true, $output, $fake, static fn () => 21, 15, true), 'OTE reconciliation scope refused.');
        $refuse(fn () => new GuardedTransport('offline-secret-token', false, $output, $fake, static fn () => 21, 16, true), 'OTE reconciliation scope refused.');
        $nameBefore = $wire->domain;
        $read = static function ($request) use ($wire) {
            return (new ResponseParser)->parse($wire->raw('https://ote.namesilo.com/apibatch', $request->command, $request->parameters()), $request, 'offline-secret-token');
        };
        same(3, ReconciliationProbe::execute($wire, $read, $prior), 'Unresolved reconciliation exits three on success or failure');
        $facts = $wire->receipt['reconciliation'];
        same(true, $wire->receipt['checks']['purchase_unresolved'] ?? null, 'Original uncertainty always retained');
        if ($inventoryFailure !== null) {
            same(true, $wire->receipt['checks']['aborted'] ?? null, 'Inventory failure recorded');
            expect(!array_key_exists('completed',$wire->receipt['checks']), 'Aborted probe has no completion field');
        }
        if ($inventoryFailure===null && $wire->receipt['mismatches'] !== []) {
            same(false, $wire->receipt['checks']['completed'] ?? null, 'Mismatched read shapes cannot complete diagnostics');
        }
        same($nameBefore, $wire->domain, 'Inventory/order names never replace original transport scope');
        same('ambiguous', $facts['category'], 'Missing original identity/amount never resolves');
        same(false, $facts['adopted'], 'No candidate is adopted');
        same(false, $facts['history_complete'], 'Observation never accepts history');
        same(false, $facts['original_amount_retained'], 'Observed order amount cannot become original authorization');
        foreach (['registerDomain', 'renewDomain', 'contactAdd', 'getAccountBalance', 'getPrices', 'checkRegisterAvailability', 'contactList'] as $command) {
            $refuse(fn () => $wire->raw('https://ote.namesilo.com/apibatch', $command, []), 'OTE reconciliation reads only.');
        }
        $wire->receipt['reconciliation'] = $facts; $wire->save();
        $stored = file_get_contents($output.'/receipt.json');
        foreach (['contract-ote-123456789abc.com', 'unrelated-placeholder.com', '8.95', 'offline-secret-token', '901'] as $value) {
            expect(!str_contains($stored, $value), 'Scalar account/financial values omitted');
        }
        return [$facts, $commands, $wire->receipt['checks']];
    } finally { @unlink($output.'/receipt.json'); rmdir($output); }
};
[$facts, $commands, $checks] = $probe([], []);
same(true,$checks['completed']??null,'Known clean empty inventory completes diagnostics');
same(0, $facts['consistent_registration_rows'], 'No match remains ambiguous');
same(['listOrders', 'listDomains'], $commands, 'No speculative domain read or extra probe');
same(true, $facts['inventory_empty'], 'Well-formed empty inventory is observed, never definitive absence');
[$facts] = $probe([$header], ['901' => $detail]);
same(1, $facts['consistent_registration_rows'], 'One internally consistent row still cannot be adopted');
$h2 = array_replace($header, ['order_number' => '902']); $d2 = array_replace($detail, ['order_number' => '902']);
[$facts] = $probe([$header, $h2], ['901' => $detail, '902' => $d2]);
same(2, $facts['consistent_registration_rows'], 'Two matching rows remain ambiguous');
[$facts] = $probe([$header], ['901' => array_replace($detail, ['total' => '9.95'])]);
same(0, $facts['consistent_registration_rows'], 'Wrong amount is not adopted');
[$facts, $commands] = $probe([array_replace($header, ['order_date' => '2026-10-19 00:40:00'])], []);
same(['listOrders', 'listDomains'], $commands, 'Wrong window not followed');
[$facts, $commands] = $probe([$header, $h2], ['901' => $detail, '902' => $d2], 3);
same(3, count($commands), 'Budget bounds detail exploration without retries');
[$facts] = $probe([], [], failedOrders: true);
same(false, $facts['orders_read_success'], 'Unknown history never means absent purchase');
[$facts,,$checks] = $probe([], [], inventory: ['domains' => null, 'pager' => ['page' => 1, 'pageSize' => 10]]);
same(false,$checks['completed']??null,'Known malformed non-aborted inventory explicitly fails completion');
expect(!isset($checks['aborted']),'Malformed inventory completes the diagnostic read without aborting');
same(null, $facts['inventory_empty'], 'Malformed inventory does not mean empty');
foreach ([0, -1, 1.0, 'bad', 2] as $page) {
    [$facts] = $probe([], [], inventory: ['domains' => [], 'pager' => ['page' => $page, 'pageSize' => 10, 'total' => 0]]);
    same(null, $facts['inventory_empty'], 'Malformed or unrelated inventory page cannot mean empty');
}
foreach ([0, -1, 10.0, 'bad', 11] as $size) {
    [$facts] = $probe([], [], inventory: ['domains' => [], 'pager' => ['page' => 1, 'pageSize' => $size, 'total' => 0]]);
    same(null, $facts['inventory_empty'], 'Malformed or unrelated inventory page size cannot mean empty');
}
foreach ([-1, 0.0, 'bad', null, false] as $total) {
    [$facts] = $probe([], [], inventory: ['domains' => [], 'pager' => ['page' => 1, 'pageSize' => 10, 'total' => $total]]);
    same(null, $facts['inventory_empty'], 'Malformed inventory total cannot mean empty or nonempty');
}
foreach (['throw', 'http'] as $failure) {
    [$facts, $commands] = $probe([], [], inventoryFailure: $failure);
    same(null, $facts['inventory_empty'], 'Failed inventory cannot prove empty');
    same(['listOrders', 'listDomains'], $commands, 'Failed inventory is not retried');
}
$mapping = PurchaseMapping::register('example.com', 1, false, '101', ['ns1.namesilo.com', 'ns2.namesilo.com']);
$parse = static fn ($reply) => (new ResponseParser)->parse(json_encode(['request' => ['operation' => 'registerDomain'], 'reply' => $reply]), $mapping, 'offline-secret-token');
same(Outcome::Unknown, $parse(['code' => null, 'detail' => null])->outcome, 'Observed null acknowledgement stays unknown');
same(Outcome::Success, $parse(['code' => 300, 'detail' => 'synthetic', 'domain' => 'example.com', 'order_amount' => '8.95'])->outcome, 'Documented correlated acknowledgement remains accepted');
echo "Lost-attempt reconciliation: ambiguity, read-only scope, time, exact money and redaction passed\n";
