<?php
// Synthetic Enom 444f31c claim/seal regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/operation-fixture.php';
use Illuminate\Support\Facades\DB;

// Initial lifecycle matrix: both acquisition actions, before claim and during unlocked preflight.
foreach (['register', 'transfer'] as $action) {
    foreach (['cancelled', 'suspended'] as $status) {
        foreach (['before_claim', 'during_preflight'] as $phase) {
            $scenario = $action.'-'.$status.'-'.$phase;
            $op = $new(placeholderComFixtureName('lifecycle-'.str_replace('_', '-', $scenario).'-placeholder.com'), action: $action);
            $changeStatus = static fn () => DB::table('services')->where('id', $op->service_id)->update(['status' => $status]);
            if ($phase === 'before_claim') { $changeStatus(); }
            else { $preflight->during = $changeStatus; }
            $money = $nativeMoney(); $preflights = $preflight->calls; $providerReads = $reads->calls; $writes = $writer->calls;
            $result = $engine->execute($op->id); $preflight->during = null;
            same(['held', $phase === 'before_claim' ? 'claim_authority_invalid' : 'seal_authority_invalid'],
                [$result->state, $result->safe_code], 'M7 '.$scenario.' exact neutral refusal');
            same([$preflights + ($phase === 'during_preflight' ? 1 : 0), $providerReads, $writes], [$preflight->calls, $reads->calls, $writer->calls],
                'M7 '.$scenario.' no preclaim reads or provider writes');
            same($money, $nativeMoney(), 'M7 '.$scenario.' preserves native finance and jobs');
            same([$status, null, $action === 'transfer' ? 'transfer_pending' : 'registration_pending', $op->domain->domain, null, null],
                [App\Models\Service::find($op->service_id)->status, App\Models\Service::find($op->service_id)->expires_at,
                    $result->domain->status, $result->domain->reservation_name, $result->domain->provider_expires_at, $result->confirmed_expires_at],
                'M7 '.$scenario.' retains lifecycle, reservation and unconfirmed expiry');
            $attempt = $result->attempt ?? [];
            same([$phase === 'before_claim' ? null : 'absent', false, false, null],
                [$attempt['seal'] ?? null, array_key_exists('execution', $attempt), array_key_exists('refund', $result->funding ?? []), $result->completed_at],
                'M7 '.$scenario.' has no seal, execution, credit or terminal disposition');
            $beforeRetry = [$preflight->calls, $reads->calls, $writer->calls, $nativeMoney(), $result->getRawOriginal()];
            $nativeBeforeRetry = nativeFinancialSnapshot($op);
            $engine->execute($op->id);
            same($nativeBeforeRetry, nativeFinancialSnapshot($op), 'M7 '.$scenario.' retry preserves native/financial state');
            same($beforeRetry, [$preflight->calls, $reads->calls, $writer->calls, $nativeMoney(), $result->fresh()->getRawOriginal()],
                'M7 '.$scenario.' cannot automatically retry');
        }
    }
}
echo "Initial lifecycle claim and seal checks passed\n";
$nativeSuccessful = true;
