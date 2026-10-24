<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
foreach (['Shapes', 'ParserDiagnostics', 'PurchaseAttempt', 'GuardedTransport'] as $class) { require __DIR__.'/contract/'.$class.'.php'; }
use OteContract\GuardedTransport;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, RequestBudget, RequestContext, RequestOrigin, SystemClock};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Client;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Outcome, RegistrarRequest, RegistrarResult, TransportResponse};

$output = sys_get_temp_dir().'/ote-normal-'.bin2hex(random_bytes(6)); mkdir($output, 0700);
$key = getenv('NAMESILO_SANDBOX_API_KEY'); putenv('NAMESILO_SANDBOX_API_KEY=offline-secret-token');
$fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/synthetic-success.json'), true, 24, JSON_THROW_ON_ERROR);
try {
    foreach ([['--read', true], ['--read', false], ['--write', true], ['--profile-read', true]] as [$mode, $malformed]) {
        $wire = new GuardedTransport('offline-secret-token', $mode === '--write', $output,
            static function (string $command, array $parameters) use ($fixtures, $malformed): TransportResponse {
                $body = $fixtures[$command] ?? ['request' => ['operation' => $command, 'ip' => '192.0.2.1'],
                    'reply' => ['code' => 300, 'detail' => 'synthetic']];
                if ($command === 'getPrices') { $body['reply']['net'] = $body['reply']['com']; }
                if ($command === 'checkRegisterAvailability') {
                    $body['reply'] = ['code' => 300, 'detail' => 'synthetic', 'available' => array_map(
                        static fn ($domain) => ['domain' => $domain, 'price' => 12.34, 'premium' => 0, 'duration' => 1],
                        explode(',', $parameters['domains']))];
                }
                if ($command === 'listOrders') { $body['reply']['order'] = []; }
                if ($command === 'listDomains') {
                    $body['reply'] += ['domains' => $malformed ? 'malformed' : [], 'pager' => ['page' => 1, 'pageSize' => 10, 'total' => 0]];
                }
                return new TransportResponse(200, json_encode($body, JSON_THROW_ON_ERROR));
            }, static fn () => 21);
        $binding = new AccountBinding('namesilo', 1, Environment::Test, 'contract-local-fixture');
        $clock = new SystemClock;
        $client = new Client($binding, 'offline-secret-token', $wire);
        $ctx = new RequestContext($binding, RequestOrigin::Interactive, $clock->now()->modify('+900 seconds'), new RequestBudget(60), $clock);
        $read = static function (RegistrarRequest $request) use ($client, $ctx, $wire): RegistrarResult {
            $result = $client->read($request, $ctx);
            if ($result->outcome !== Outcome::Success) { $wire->mismatch($request->command, 'production_parser_not_success'); }
            $wire->save(); return $result;
        };
        $exit = null;
        require __DIR__.'/contract/normal.php';
        same($malformed ? 1 : 0, $exit, 'Normal phase exits nonzero on a shape mismatch: '.$mode);
        $receipt = json_decode(file_get_contents($output.'/receipt.json'), true, 24, JSON_THROW_ON_ERROR);
        same(!$malformed, $receipt['checks']['completed'], 'Persisted normal completion honors mismatches: '.$mode);
        same(true, $receipt['checks']['apibatch_supported'], 'Normal phase reaches its final batch check');
        if ($malformed) {
            expect(in_array(['command' => 'listDomains', 'field' => 'reply.domains.collection'], $receipt['mismatches'], true), 'Malformed inventory is recorded');
            same(['listDomains'], array_values(array_unique(array_column(array_filter($receipt['mismatches'],
                static fn ($row) => $row['field'] !== 'completed'), 'command'))), 'Inventory is the only failed read');
            if ($mode !== '--read') { same('blocked_read_gate', $receipt['checks']['registration'], 'Mismatch blocks the write/profile phase'); }
        } else { same([], $receipt['mismatches'], 'Valid reads complete without mismatches'); }
    }
} finally {
    putenv($key === false ? 'NAMESILO_SANDBOX_API_KEY' : 'NAMESILO_SANDBOX_API_KEY='.$key);
    foreach (glob($output.'/*') as $file) { unlink($file); } rmdir($output);
}
echo "OTE normal completion requires matching reads\n";
