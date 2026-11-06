<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/namesilo-registration-base.php';
foreach ($nameSiloModes as $mode) {
    $registration->mode = $mode; $registration->calls = []; $registration->diagnostics = []; $makeEngine($binding, $exchange);
    $result = $runRegistration($nsNew(placeholderComFixtureName('ns-'.$mode.'-placeholder.com')));
    if (in_array($mode, ['default', 'wrongcontact', 'authoritydrift', 'wrongphone', 'missingphone'], true)) {
        if ($mode === 'authoritydrift') {
            same(['authority_before' => true, 'authority_after' => false], $registration->diagnostics, 'Actual original claim snapshot detects policy drift');
        }
        same('held', $result->state, 'Invalid profile proof holds'); same('absent', $result->attempt['seal'], 'No purchase seal on invalid proof');
        same(['contactAdd', 'contactList'], $registration->calls, 'Default/mismatched profile never reaches purchase'); continue;
    }
    if ($mode === 'unknown') {
        same('uncertain', $result->state, 'Unknown purchase stays unresolved');
        same(['contactAdd', 'contactList', 'registerDomain'], $registration->calls, 'No follow-up/retry after unknown');
    } else {
        $corrections = match ($mode) { '301' => ['nameservers'], '302' => ['contacts'], 'badns', 'mismatch' => ['nameservers'], 'stillon' => ['auto_renew'],
            'lost' => ['auto_renew', 'nameservers', 'contacts', 'privacy'], default => [] };
        if (in_array($mode, ['badexpiry', 'rereadfail'], true)) { $corrections = ['auto_renew', 'nameservers', 'contacts', 'privacy']; }
        $assertRetained($result, $corrections);
        $fact = $result->domain->fresh()->observation['nameservers'] ?? null;
        if ($mode === '300') {
            same(['ns2.provider.example', 'ns1.provider.example'], $fact['nameservers'] ?? null, 'NameSilo domain-info values are stored as observed');
            same('observed', $fact['state'] ?? null, 'NameSilo provenance is explicit');
            $page = $ownerPage($result);
            expect(str_contains($page, '<dt>Nameservers</dt><dd><span class="domains-wrap">ns2.provider.example, ns1.provider.example</span>')
                && !str_contains($page, 'Fills in by itself after the first check'), 'NameSilo registration nameservers reach the owner detail page');
        } elseif ($mode === 'badns') {
            same(['ns1.provider.example', 'ns2.provider.example'], $fact['nameservers'] ?? null, 'Submitted values are retained when domain-info nameservers are malformed');
            same('requested', $fact['state'] ?? null, 'Malformed readback does not confirm submitted values');
        } elseif ($mode === 'mismatch') {
            same(['ns3.provider.example', 'ns1.provider.example'], $fact['nameservers'] ?? null, 'Mismatched domain-info values remain observed');
            same('observed', $fact['state'] ?? null, 'Mismatch does not become a requested value');
        } elseif (in_array($mode, ['badexpiry', 'rereadfail'], true)) {
            same(['ns2.provider.example', 'ns1.provider.example'], $fact['nameservers'] ?? null, 'Valid first domain-info nameservers survive unrelated readback errors');
            same('observed', $fact['state'] ?? null, 'First domain-info nameservers retain observed provenance');
        }
        same(null, $result->attempt['registration']['order_reference'], 'No fabricated NameSilo order ID');
        same($mode === 'amount' ? null : '99.00', $result->attempt['charge']['amount'] ?? null, 'Charge observation retained without accepting actual cost');
        same('6.00', $result->attempt['authorized_cost']['amount'], 'No retail cost floor at dispatch');
    }
    if (in_array($mode, ['remove', 'stillon'], true)) {
        same(['contactAdd', 'contactList', 'registerDomain', 'getDomainInfo', 'removeAutoRenewal', 'getDomainInfo'], $registration->calls, 'Removal followed by fresh readback');
    }
    $nativeBeforeReplay = nativeFinancialSnapshot($result);
    $before = $registration->calls; $engine->execute($result->id); same($before, $registration->calls, 'No replay/refund/fallback');
    same($nativeBeforeReplay, nativeFinancialSnapshot($result), 'Replay preserves native/financial state');
    expect($profiles->hasProof($result), 'Exact proof retained for current payload');
    $foreign = clone $result; $foreign->account_fingerprint = str_repeat('f', 64); expect(!$profiles->hasProof($foreign), 'Proof account isolated');
    $changed = clone $result; $changed->payload = $result->payload + ['changed' => true]; expect(!$profiles->hasProof($changed), 'Proof payload isolated');
    $otherOwner = clone $result; $otherDomain = clone $result->domain; $otherDomain->user_id = $user->id + 999; $otherOwner->setRelation('domain', $otherDomain);
    expect(!$profiles->hasProof($otherOwner), 'Proof owner isolated');
    $clock->instant = $clock->now()->modify('+121 seconds'); expect(!$profiles->hasProof($result), 'Stale profile proof refuses'); $clock->instant = $clock->now()->modify('-121 seconds');
}
echo "NameSilo registration cases passed\n";
$nativeSuccessful = true;
