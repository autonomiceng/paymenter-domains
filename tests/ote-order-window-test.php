<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
foreach (['Shapes', 'ParserDiagnostics', 'PurchaseAttempt', 'RenewalProbe', 'GuardedTransport'] as $class) { require __DIR__.'/contract/'.$class.'.php'; }
use OteContract\GuardedTransport;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, RequestBudget, RequestContext, RequestOrigin, SystemClock};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Client;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{RegistrarRequest, TransportResponse};

$output = sys_get_temp_dir().'/ote-order-window-'.bin2hex(random_bytes(6)); mkdir($output, 0700);
$fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/synthetic-success.json'), true, 24, JSON_THROW_ON_ERROR);
try {
    $wire = new GuardedTransport('offline-secret-token', false, $output,
        static function (string $command, array $parameters) use (&$wire, $fixtures): TransportResponse {
            $body = $fixtures[$command];
            $today = gmdate('Y-m-d');
            if ($command === 'listOrders') {
                // Same-day OTE orders were absent with today's date_to; tomorrow returned them.
                $body['reply']['order'] = $parameters['date_to'] > $today
                    ? [['order_number' => '901', 'order_date' => $today.' 21:08:29', 'method' => 'AccountFunds', 'total' => '6.99']] : [];
            }
            if ($command === 'orderDetails') {
                $body['reply'] = ['code' => 300, 'detail' => 'synthetic', 'order_number' => '901',
                    'order_date' => $today.' 21:08:29', 'method' => 'AccountFunds', 'total' => '6.99',
                    'order_details' => [['description' => $wire->domain.' - Registration', 'years_qty' => '1',
                        'price' => '6.99', 'subtotal' => '6.99', 'status' => 'Processed']]];
            }
            return new TransportResponse(200, json_encode($body, JSON_THROW_ON_ERROR));
        }, static fn () => 21, limit: 6);
    $binding = new AccountBinding('namesilo', 1, Environment::Test, 'synthetic');
    $clock = new SystemClock; $started = $clock->now();
    $reader = new Client($binding, 'offline-secret-token', $wire);
    $context = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+300 seconds'), new RequestBudget(10), $clock);
    $get = static fn (RegistrarRequest $request) => $reader->read($request, $context);
    $raw = static fn () => throw new LogicException('Ancillary writes have no test budget');
    try { require __DIR__.'/contract/write-checks.php'; }
    catch (LogicException $error) { same('Ancillary writes have no test budget', $error->getMessage(), 'Only ancillary writes stop this probe'); }
    same(true, $wire->receipt['checks']['unique_registration_history_row'], 'Runner includes same-day title-case order');
    same(true, $wire->receipt['checks']['charge_evidence_shape_matches'], 'Runner correlates observed charge shape');
    same(1, $wire->receipt['checks']['production_charge_candidate_count'], 'Runner and production agree on observed registration');
    same(false, $wire->receipt['checks']['production_history_complete_without_acceptance'], 'No history acceptance inferred');
} finally {
    foreach (glob($output.'/*') as $file) { unlink($file); } rmdir($output);
}
echo "OTE same-day order window and title-case correlation passed\n";
