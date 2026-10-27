<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Outcome, RegistrarRequest};

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
$completed = $wire->receipt['mismatches'] === [];
$wire->check('completed', $completed); $exit = $completed ? 0 : 1;
