<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic transport only.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\OperationSchema;
use Paymenter\Extensions\Servers\Domains\Services\RenewalFundingLedger;
[$op,$d,$binding] = $makeRenewal(placeholderComFixtureName('renewal-deadline-placeholder.com'));
$setNow = static fn (DateTimeImmutable $at) => $clock->instant = $at;
// The 30-second account slot can end while the seal commits or while the spend waits for its locks. Either way the
// exchange never starts and the sealed attempt keeps a local refusal, never an unknown outcome.
$original = $op->getRawOriginal(); $start = $clock->now(); $expireOn = null;
DB::listen(static function ($query) use (&$expireOn, $clock, $setNow): void {
    if ($expireOn !== null && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, $expireOn)) { $expireOn = null; $setNow($clock->now()->modify('+31 seconds')); }
});
foreach (['"sealed_at" = ?' => 'before the transport callback', '"grant_consumed_at" = ?' => 'during the spend'] as $marker => $case) {
    $steps = DB::table(OperationSchema::STEPS)->where('operation_id', $op->id)->pluck('id');
    DB::table(OperationSchema::EVIDENCE)->where('operation_id', $op->id)->delete();
    DB::table(OperationSchema::ATTEMPTS)->whereIn('step_id', $steps)->delete();
    DB::table(OperationSchema::STEPS)->where('operation_id', $op->id)->delete();
    DB::table(RenewalFundingLedger::TABLE)->where(['invoice_item_id' => $op->invoice_item_id, 'retry_number' => $op->retry_number])->delete();
    DB::table('domains_operations')->where('id', $op->id)->update($original); $setNow($start); Cache::flush(); $state->writes = 0;
    [$engine,$writer] = $compose($binding); $money = $nativeMoney(); $expireOn = $marker;
    $result = $engine->run($op->id, $writer); $expireOn = null;
    same(0, $state->writes, 'Expired account slot sends no renewal: '.$case);
    same(['held', 'renewal_definitely_unsent'], [$result->state, $result->safe_code], 'Expired account slot is a local refusal: '.$case);
    expect(DB::table(OperationSchema::EVIDENCE)->where(['operation_id' => $op->id, 'kind' => 'reply', 'source' => 'local_refusal'])->exists(), 'Sealed attempt keeps the local refusal: '.$case);
    same($money, $nativeMoney(), 'Native finance unchanged on an expired slot: '.$case);
}
(require __DIR__.'/Support/renewal-deadline-cases.php')($op,$d,$binding,$compose,$clock,$state,$setNow,$nativeMoney);
$nativeSuccessful = true;
echo "Checkout renewal entry deadlines passed\n";
