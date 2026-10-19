<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
ob_start(); // Never expose native framework output or error traces.
ini_set('display_errors', '0'); ini_set('log_errors', '0'); ini_set('zend.exception_ignore_args', '1');
require_once dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/PurchaseAttempt.php'; require __DIR__.'/RenewalProbe.php'; require __DIR__.'/RenewalContinuation.php'; require __DIR__.'/ProfileDiagnostics.php'; require __DIR__.'/Shapes.php'; require __DIR__.'/ParserDiagnostics.php'; require __DIR__.'/GuardedTransport.php';
use OteContract\GuardedTransport;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, RequestBudget, RequestContext, RequestOrigin, SystemClock};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Client;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Outcome, RegistrarRequest, RegistrarResult};

$wire = null; $exit = 1;
try {
    $mode = $argv[1] ?? '';
    if ($argc !== 2 || !in_array($mode, ['--read', '--write', '--profile-read', '--reconcile', '--renewal-only', '--self-test'], true)) { throw new RuntimeException('Explicit phase required.'); }
    if ($mode === '--self-test') { require __DIR__.'/offline.php'; $exit = 0; }
    else {
        GuardedTransport::window((int) gmdate('G'));
        $output = (string) getenv('OTE_OUTPUT');
        if ($output === '' || realpath($output) !== $output || !is_dir($output) || (fileperms($output) & 077) !== 0) { throw new RuntimeException('Private receipt directory required.'); }
        $limit = (string) getenv('OTE_REQUEST_LIMIT');
        if (!preg_match('/^(?:[1-9]|[1-5][0-9]|60)$/D', $limit)) { throw new RuntimeException('Explicit request limit required.'); }
        $wire = new GuardedTransport((string) getenv('NAMESILO_SANDBOX_API_KEY'), in_array($mode, ['--write', '--renewal-only'], true), $output, limit: (int) $limit, reconcile: $mode === '--reconcile',
            continuationReceipt: $mode === '--renewal-only' ? (string) getenv('OTE_ATTEMPT_RECEIPT') : null);
        $wire->receipt['phase'] = $mode === '--write' ? 'read+write' : ($mode === '--profile-read' ? 'read+profile' : 'read');
        $wire->receipt['reference'] = 'docs/design/api-contract.md; tests/fixtures/synthetic-success.json';
        $binding = new AccountBinding('namesilo', 1, Environment::Test, 'contract-local-fixture');
        $clock = new SystemClock;
        $client = new Client($binding, (string) getenv('NAMESILO_SANDBOX_API_KEY'), $wire);
        $ctx = new RequestContext($binding, RequestOrigin::Interactive, $clock->now()->modify('+900 seconds'), new RequestBudget((int) $limit), $clock);
        $read = static function (RegistrarRequest $request) use ($client, $ctx, $wire): RegistrarResult {
            try { $result = $client->read($request, $ctx); }
            catch (Throwable) { $result = new RegistrarResult(Outcome::Unknown); }
            if ($result->outcome !== Outcome::Success) { $wire->mismatch($request->command, 'production_parser_not_success'); }
            $wire->save(); return $result;
        };
        if ($mode === '--renewal-only') {
            $wire->receipt['phase'] = 'renewal-continuation';
            \OteContract\RenewalProbe::run($wire, $client, $binding, $clock);
            $wire->check('completed', true); $exit = 0;
        } elseif ($mode === '--reconcile') {
            require __DIR__.'/ReconciliationProbe.php';
            $prior = json_decode(file_get_contents((string) getenv('OTE_ATTEMPT_RECEIPT')), true, 24, JSON_THROW_ON_ERROR);
            $wire->receipt['phase'] = 'reconcile';
            $exit = \OteContract\ReconciliationProbe::execute($wire, $read, $prior);
        } else {
        $readBalance = $read(RegistrarRequest::balance()); $readPrices = $read(RegistrarRequest::prices());
        $availability = $read(RegistrarRequest::availability([$wire->domain, substr($wire->domain, 0, -4).'.net'], false));
        if ($mode === '--read') {
            $single = $read(RegistrarRequest::availability([$wire->domain], false));
            $row = $single->value('available', [])[0] ?? [];
            $wire->check('single_name_available_nonpremium', $single->outcome === Outcome::Success
                && ($row['domain'] ?? null) === $wire->domain && ($row['premium'] ?? null) === false);
            try {
                $cost = new \Paymenter\Extensions\Servers\Domains\Data\Money($readPrices->catalogueRows()['com']['registration'] ?? '', 'USD');
                $funds = new \Paymenter\Extensions\Servers\Domains\Data\Money($readBalance->value('balance', ''), 'USD');
                $sufficient = \Paymenter\Extensions\Servers\Domains\Services\WriteMoney::sufficient($funds, $cost);
            } catch (Throwable) { $sufficient = false; }
            $wire->check('single_name_price_balance_preflight', $sufficient);
        }
        $wire->raw('https://ote.namesilo.com/api', 'listDomains', ['pageSize' => 10, 'page' => 1]);
        $read(RegistrarRequest::listOrders(gmdate('Y-m-d', time() - 86400), gmdate('Y-m-d')));
        try { $batchBody = $wire->raw('https://ote.namesilo.com/apibatch', 'getAccountBalance', []);
        $batchResult = (new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\ResponseParser)->parse($batchBody, RegistrarRequest::balance(), (string) getenv('NAMESILO_SANDBOX_API_KEY'));
        $batch = $batchResult->outcome === Outcome::Success
            && $wire->receipt['requests'][array_key_last($wire->receipt['requests'])]['http_status'] === 200;
        } catch (Throwable) { $batch = false; }
        $wire->check('apibatch_supported', $batch);
        $wire->check('renew_auto_renew_reference', 'not_documented; requires_one_approved_synthetic_probe');
        if (in_array($mode, ['--write', '--profile-read'], true)) {
            if (!$batch || $wire->receipt['mismatches']) { $wire->check('registration', 'blocked_read_gate'); }
            else { require __DIR__.'/registration.php'; }
        }
        if ($wire->receipt['checks']['purchase_unresolved'] ?? false) { throw new RuntimeException('OTE purchase needs investigation.'); }
        $wire->check('completed', true); $exit = 0;
        }
    }
} catch (Throwable) {
    if ($wire) { $wire->check('aborted', true); if ($wire->receipt['checks']['purchase_unresolved'] ?? false) { $exit = 3; } }
}
if ($wire) { $wire->save(); }
while (ob_get_level()) { ob_end_clean(); }
exit($exit);
