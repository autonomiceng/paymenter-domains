<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Data\{Action, AttemptEvidence, ExecutionRecord, Query, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\RegistrarRequest;

$correlate = static function (string $label) use ($wire, $get): void {
    if ($wire->remaining() < 6) { $wire->check($label.'_charge_correlation', 'skipped_budget'); return; }
    $get(RegistrarRequest::listOrders(gmdate('Y-m-d', time() - 86400), gmdate('Y-m-d')));
    $orders = $wire->latest('listOrders')['order'] ?? [];
    if (is_array($orders) && !array_is_list($orders)) { $orders = [$orders]; }
    $orders = is_array($orders) ? $orders : [];
    // Newest five headers only; broad date range avoids assuming the provider timezone.
    usort($orders, static fn ($a, $b) => strcmp((string) ($b['order_date'] ?? ''), (string) ($a['order_date'] ?? ''))
        ?: (strlen((string) ($b['order_number'] ?? '')) <=> strlen((string) ($a['order_number'] ?? '')))
        ?: strcmp((string) ($b['order_number'] ?? ''), (string) ($a['order_number'] ?? '')));
    $matches = 0; $chargeShape = false; $timeShape = false;
    foreach (array_slice($orders, 0, 5) as $row) {
        $id = $row['order_number'] ?? null;
        if (!is_string($id) && !is_int($id)) { continue; }
        try { $detail = $get(RegistrarRequest::orderDetails((string) $id)); } catch (Throwable) { continue; }
        $items = $detail?->value('order_details', []) ?? [];
        if (is_array($items) && !array_is_list($items)) { $items = [$items]; }
        foreach ($items as $item) {
            if (($item['description'] ?? null) !== $wire->domain.' - '.$label) { continue; }
            $matches++;
            $chargeShape = ($item['years_qty'] ?? null) === '1' && ($item['status'] ?? null) === 'Processed'
                && is_string($item['subtotal'] ?? null) && !array_key_exists('credited_amount', $item) && !array_key_exists('credited_date', $item);
            $timeShape = is_string($detail?->value('order_date'))
                && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $detail->value('order_date')) === 1;
        }
    }
    $wire->check('unique_'.$label.'_history_row', $matches === 1);
    $wire->check($label === 'registration' ? 'charge_evidence_shape_matches' : 'renewal_charge_evidence_shape_matches', $matches === 1 && $chargeShape && $timeShape);
    if ($matches !== 1 || !$chargeShape || !$timeShape) { $wire->mismatch('orderDetails', $label.'_charge_correlation'); }
};
$correlate('registration');
// Real reconciliation deliberately keeps timezone/lag/retention acceptance unset: a single
// observation cannot establish all-pages history or justify production financial completion.
$driver = new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Driver($reader, ['renew'], $clock);
$attempt = new AttemptEvidence($binding, new Query($wire->domain, Action::Register, 1), 'contract-registration', 1,
    ExecutionRecord::Recorded, SendState::Sent, $started, new DateTimeImmutable('now', new DateTimeZone('UTC')));
$historyContext = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
    \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $clock->now()->modify('+240 seconds'),
    new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(8), $clock);
$evidence = $driver->reconcile($attempt, $historyContext)->result()->evidence();
$wire->check('production_history_complete_without_acceptance', $evidence->historyComplete);
$wire->check('production_charge_candidate_count', count($evidence->candidates));

$raw('changeNameServers', ['domain' => $wire->domain, 'ns1' => 'NS1.NAMESILO.COM', 'ns2' => 'NS2.NAMESILO.COM']);
$get(RegistrarRequest::domainInfo($wire->domain));
$ns = $wire->latest('getDomainInfo')['nameservers'] ?? [];
if (is_array($ns) && !array_is_list($ns)) { $ns = [$ns]; }
$hosts = array_map(static fn ($r) => strtolower($r['nameserver'] ?? ''), is_array($ns) ? $ns : []); sort($hosts);
$wire->check('nameservers_readback', $hosts === ['ns1.namesilo.com', 'ns2.namesilo.com']);
foreach (['domainUnlock' => 'No', 'domainLock' => 'Yes'] as $command => $flag) {
    $raw($command, ['domain' => $wire->domain]); $info = $get(RegistrarRequest::domainInfo($wire->domain));
    $wire->check($command.'_readback', $info?->value('locked') === $flag);
}
foreach ([1, 2] as $i) {
    $raw('removeAutoRenewal', ['domain' => $wire->domain]);
    $code = (int) ($wire->latest('removeAutoRenewal')['code'] ?? 0);
    $wire->check('remove_auto_renew_code_'.$i, $code);
    $wire->check('remove_auto_renew_code_expected_'.$i, in_array($code, [250, 251, 300], true));
}
$info = $get(RegistrarRequest::domainInfo($wire->domain)); $wire->check('remove_auto_renew_readback', $info?->value('auto_renew') === 'No');
if ($wire->remaining() >= 4) {
$raw('dnsAddRecord', ['domain' => $wire->domain, 'rrtype' => 'TXT', 'rrhost' => '_contract', 'rrvalue' => 'synthetic-contract', 'rrttl' => 3600]);
$id = $wire->latest('dnsAddRecord')['record_id'] ?? null;
$raw('dnsListRecords', ['domain' => $wire->domain]);
$records = $wire->latest('dnsListRecords')['resource_record'] ?? [];
if (is_array($records) && !array_is_list($records)) { $records = [$records]; }
$found = array_filter(is_array($records) ? $records : [], static fn ($r) => (string) ($r['record_id'] ?? '') === (string) $id
    && ($r['type'] ?? '') === 'TXT' && ($r['value'] ?? '') === 'synthetic-contract');
$wire->check('txt_readback', $id !== null && count($found) === 1);
if ($id !== null) {
    $raw('dnsDeleteRecord', ['domain' => $wire->domain, 'rrid' => (string) $id]);
    $raw('dnsListRecords', ['domain' => $wire->domain]);
    $records = $wire->latest('dnsListRecords')['resource_record'] ?? [];
    if (is_array($records) && !array_is_list($records)) { $records = [$records]; }
    $wire->check('txt_deleted', !array_filter(is_array($records) ? $records : [], static fn ($r) => (string) ($r['record_id'] ?? '') === (string) $id));
}
} else { $wire->check('txt_sequence', 'skipped_budget'); }
// The cached public API reference is silent about auto_renew on renewDomain.
// Probe exactly once, for one year, only on this process's freshly registered name.
$info = $get(RegistrarRequest::domainInfo($wire->domain)); $before = $info?->value('expires');
$expiry = \OteContract\Shapes::strictDate($before);
if ($wire->remaining() < 8) { $wire->check('renew_probe', 'skipped_budget'); }
elseif ($info?->value('status') === 'Active' && $info->value('auto_renew') === 'No' && $expiry !== null
    && $expiry->getTimestamp() > time() + 172800) {
    $renewBody = $raw('renewDomain', ['domain' => $wire->domain, 'years' => 1, 'auto_renew' => 0]);
    $renewParsed = (new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\ResponseParser)->parse($renewBody,
        \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\PurchaseMapping::renew($wire->domain, 1, false), (string) getenv('NAMESILO_SANDBOX_API_KEY'));
    $code = (int) ($wire->latest('renewDomain')['code'] ?? 0);
    $info = $get(RegistrarRequest::domainInfo($wire->domain));
    $wire->check('renew_auto_renew_request_accepted', $code === 300 && $renewParsed->outcome === \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Outcome::Success);
    $wire->check('renew_auto_renew_remained_off', $info?->value('auto_renew') === 'No');
    $wire->check('renew_added_one_year', $info?->value('expires') === $expiry->modify('+1 year')->format('Y-m-d'));
    if (!in_array($renewParsed->outcome, [\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Outcome::Success, \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Outcome::Rejected], true)) { $wire->check('purchase_unresolved', true); }
    $correlate('renewal');
    $wire->check('renew_parameter_effect', 'request_acceptance_does_not_prove_parameter_honored');
} else { $wire->check('renew_probe', 'blocked_without_active_owned_autorenew_off_readback'); $wire->mismatch('renewDomain', 'probe_readback_gate'); }
