<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
foreach ($registrationModes as $mode) {
    $registration->mode = $mode; $registration->calls = []; $makeEngine($binding, $exchange);
    $operation = $new('enom-'.$mode.'.example.com');
    if ($mode === 'defaultdns') { $operation->update(['payload' => [...$operation->payload, 'nameservers' => []]]); }
    $result = $runRegistration($operation);
    if (in_array($mode, ['unknown', 'pending', 'processing', 'queued', '1300', 'missingid', 'invalidid', 'missingexpiry', 'invalidexpiry'], true)) {
        same('uncertain', $result->state, 'Incomplete purchase stays unresolved'); same(['Purchase'], $registration->calls, 'No follow-ups on pending/unknown');
        same(in_array($mode, ['pending', 'processing', 'queued', '1300'], true) ? 'pending' : 'unknown', $result->attempt['execution']['disposition'], 'Source Purchase classification');
        expect(!isset($result->attempt['registration']), 'No completed acceptance for pending/unknown');
    }
    elseif ($mode === 'authority') {
        same('uncertain', $result->state, 'Changed authority cannot complete');
        same('accepted_with_discrepancy', $result->attempt['execution']['disposition'], 'Authority change retains original accepted execution');
        same('1234', $result->attempt['evidence']['registration']['orderReference'], 'Original acceptance facts survive authority change');
        same(['nameservers'], array_keys($result->attempt['corrections']), 'Authority change retains correction requirements');
        same(null, $result->domain->fresh()->observation['nameservers'] ?? null, 'Nameserver facts are not saved after authority changes');
        same('registration_authority_changed', $result->safe_code, 'Specific completion authority refusal');
    }
    else {
        $assertRetained($result, in_array($mode, ['clean', 'defaultdns', 'finishfailure'], true) ? [] : ['nameservers']); same('1234', $result->attempt['registration']['order_reference'], 'Original order retained');
        $fact = $result->domain->fresh()->observation['nameservers'] ?? null;
        if (in_array($mode, ['clean', 'finishfailure'], true)) {
            same(['ns1.provider.example', 'ns2.provider.example'], $fact['nameservers'] ?? null, 'GetDNS values are stored as observed');
            same('observed', $fact['state'] ?? null, 'GetDNS provenance is explicit');
            if ($mode === 'clean') {
                $page = $ownerPage($result);
                expect(str_contains($page, '<dt>Nameservers</dt><dd><span class="domains-wrap">ns1.provider.example, ns2.provider.example</span>')
                    && !str_contains($page, 'Fills in by itself after the first check'), 'Enom registration nameservers reach the owner detail page');
            }
        } elseif ($mode === 'lost') {
            same(['ns1.provider.example', 'ns2.provider.example'], $fact['nameservers'] ?? null, 'Submitted values are retained when GetDNS is unavailable');
            same('requested', $fact['state'] ?? null, 'Missing GetDNS is clearly unconfirmed');
        } elseif ($mode === 'mismatch') {
            same(['ns1.provider.example', 'ns3.provider.example'], $fact['nameservers'] ?? null, 'A mismatched GetDNS result remains the observed provider value');
            same('observed', $fact['state'] ?? null, 'Mismatch remains an observed result, not a requested value');
        }
        if ($mode === 'defaultdns') {
            same(null, $fact, 'Purchase has no nameserver values and does not add a registration-path read');
            same(['Purchase'], $registration->calls, 'Default-DNS registration preserves the single-send request sequence');
        }
    }
    $nativeBeforeReplay = nativeFinancialSnapshot($result);
    $before = $registration->calls; $engine->execute($result->id); same($before, $registration->calls, 'No replay of accepted/unknown attempt');
    same($nativeBeforeReplay, nativeFinancialSnapshot($result), 'Replay preserves native/financial state');
}
