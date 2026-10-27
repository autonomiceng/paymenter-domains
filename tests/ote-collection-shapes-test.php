<?php
// Synthetic values with redacted OTE singleton shapes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
foreach (['Shapes', 'ParserDiagnostics', 'PurchaseAttempt', 'GuardedTransport'] as $class) { require __DIR__.'/contract/'.$class.'.php'; }
use OteContract\{GuardedTransport, Shapes};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\TransportResponse;

$output = sys_get_temp_dir().'/ote-collections-'.bin2hex(random_bytes(6)); mkdir($output, 0700);
$dns = ['record_id' => '101', 'type' => 'TXT', 'host' => '_domainconnect.example.com', 'value' => 'synthetic', 'ttl' => 3600, 'distance' => 0];
$domain = ['domain' => 'example.com', 'created' => '2026-10-26', 'expires' => '2028-10-26'];
try {
    foreach (['dnsListRecords' => ['resource_record', $dns], 'listDomains' => ['domains', $domain]] as $command => [$field, $row]) {
        foreach (['singleton', 'empty', 'multiple', ...($command === 'listDomains' ? ['null-empty'] : [])] as $variant) {
            $rows = match ($variant) { 'singleton' => [$row], 'empty', 'null-empty' => [], 'multiple' => [$row, $row] };
            $body = ['request' => ['operation' => $command, 'ip' => '192.0.2.1'], 'reply' => ['code' => 300, 'detail' => 'synthetic',
                $field => $variant === 'null-empty' ? null : ($variant === 'singleton' ? ($command === 'listDomains' ? ['domain' => $row] : $row) : $rows)]];
            if ($command === 'listDomains') { $body['reply']['pager'] = ['page' => 1, 'pageSize' => 10, 'total' => (string) count($rows)]; }
            same([], Shapes::check($command, $body), $command.' accepts OTE '.$variant.' shape');
            $wire = new GuardedTransport('offline-secret-token', true, $output,
                static fn () => new TransportResponse(200, json_encode($body, JSON_THROW_ON_ERROR)), static fn () => 21);
            $wire->raw('https://ote.namesilo.com/api', $command, $command === 'listDomains' ? ['pageSize' => 10, 'page' => 1] : ['domain' => $wire->domain]);
            same([], $wire->receipt['mismatches'], 'Validated collection reaches success cache');
            same($rows, $wire->latest($command)[$field], 'Consumers always receive a list for '.$command.' '.$variant);
            same(Shapes::shape($body), $wire->receipt['requests'][0]['shape'], 'Receipt preserves original wire shape');
        }
        if ($command === 'listDomains') { $body['reply']['pager']['total'] = '2'; }
        foreach (['scalar' => 'malformed', 'missing-fields' => ['unexpected' => 'synthetic'], 'null' => null] as $bad) {
            $body['reply'][$field] = $bad;
            expect(Shapes::check($command, $body) !== [], 'Malformed nonempty or unproved null collection fails');
        }
        $body['reply'][$field] = $command === 'listDomains' ? ['domain' => $row, 'unexpected' => 'synthetic'] : $row + ['unexpected' => 'synthetic'];
        expect(Shapes::check($command, $body) !== [], 'Normalization never hides unknown fields');
    }
} finally {
    foreach (glob($output.'/*') as $file) { unlink($file); } rmdir($output);
}
echo "OTE DNS and inventory singleton, empty, multiple and malformed collections passed\n";
