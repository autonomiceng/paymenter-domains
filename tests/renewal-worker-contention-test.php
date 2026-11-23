<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, RenewalFundingLedger};

[$op, $domain, $binding, $invoice] = $makeRenewal(placeholderComFixtureName('worker-overlap-placeholder.com'));
$file = tempnam((string) getenv('TMPDIR'), 'renewal-workers-');
$source = DB::connection();
$source->statement('VACUUM INTO '.$source->getPdo()->quote($file));
foreach (['renew_first', 'renew_second'] as $name) {
    config(['database.connections.'.$name => [...config('database.connections.sqlite'), 'database' => $file]]);
}
DB::setDefaultConnection('renew_first');
try {
    expect(DB::connection()->getPdo() !== DB::connection('renew_second')->getPdo(), 'Two independent database sessions');
    [$first, $firstWriter] = $compose($binding);
    [$second, $secondWriter] = $compose($binding);
    $runSecond = static function () use ($second, $secondWriter, $op) {
        DB::setDefaultConnection('renew_second');
        try { return $second->run($op->id, $secondWriter); }
        finally { DB::setDefaultConnection('renew_first'); }
    };
    // Commit a conflicting revision on the independent connection while the first worker is reading Enom.
    $state->during = static function () use ($op): void {
        DB::connection('renew_second')->transaction(static function () use ($op): void {
            $row = Operation::on('renew_second')->lockForUpdate()->findOrFail($op->id);
            $row->update(['transition_revision' => $row->transition_revision + 1]);
        });
    };
    $conflict = $first->run($op->id, $firstWriter);
    same(['awaiting_payment', 2, null, 0], [$conflict->state, $conflict->transition_revision, $conflict->renewal_attempt, $state->writes],
        'An old admission cannot reserve, dispatch or release a newer revision');
    expect($conflict->renewal_admission_until !== null, 'The new admission owns its full lease');
    $clock->instant = $clock->now()->modify('+361 seconds');
    \Illuminate\Support\Facades\Cache::flush();
    $duringAdmission = $afterExpiry = null;
    $state->during = static function () use ($runSecond, &$duringAdmission): void {
        $other = $runSecond();
        $duringAdmission = [$other->state, $other->transition_revision, (array) $other->attempt];
    };
    $state->afterWrite = static function () use ($clock, $runSecond, &$afterExpiry): void {
        $clock->instant = $clock->now()->modify('+361 seconds');
        $other = $runSecond();
        $afterExpiry = [$other->state, $other->transition_revision, $other->renewal_attempt];
    };
    $money = nativeFinancialSnapshot($op->fresh());
    $reply = $first->run($op->id, $firstWriter);
    same(['awaiting_payment', 3, []], $duringAdmission, 'The second worker preserves the current admission');
    same(['uncertain', 5, $reply->renewal_attempt], $afterExpiry, 'Expired reservation is quarantined before the first reply');
    same(['uncertain', 'stale_claim', 'accepted'], [$reply->state, $reply->safe_code, $reply->attempt['execution']['disposition']],
        'Late acknowledgement observes the original reservation without reviving it');
    same(['item' => [$op->invoice_item_id => PricingRule::minor($op->amount)],
        'payment' => [$invoice->transactions()->sole()->id => PricingRule::minor($op->amount)]],
        RenewalFundingLedger::entries($reply), 'One original invoice line and signed payment are reserved');
    $runSecond();
    same(1, $state->writes, 'Both database workers and replay share one registrar send');
    same($money, nativeFinancialSnapshot($op->fresh()), 'Quarantine and replay preserve native customer money');
} finally {
    DB::setDefaultConnection('sqlite');
    DB::purge('renew_first');
    DB::purge('renew_second');
    unlink($file);
}
echo "Concurrent paid renewal workers share one reservation and one send.\n";
$nativeSuccessful = true;
