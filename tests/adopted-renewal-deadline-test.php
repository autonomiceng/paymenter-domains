<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic transport only.
declare(strict_types=1);
require __DIR__.'/Support/adopted-renewal.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingAnchor, HoldingLocks};
extract(adoptedRenewalFixture());
$snapshot = $domain->getRawOriginal('snapshot'); $evidence = $adoption->getRawOriginal('evidence');
$nativeMoney = static function (): array {
    $rows = [];
    foreach (['invoices','invoice_items','invoice_transactions','credits','jobs','failed_jobs'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
    }
    return $rows;
};
$setNow = static fn (DateTimeImmutable $at) => $clock->time = $at;
$cases = require __DIR__.'/Support/renewal-deadline-cases.php';
foreach ([2027,2028] as $year) {
    same($year === 2027 ? 'adoption' : 'completed_operation', DB::transaction(fn () => HoldingAnchor::resolve(HoldingLocks::take($domain)))->kind, 'Deadline covers current adopted anchor branch');
    $clock->time = new DateTimeImmutable($year.'-09-29T12:00:00Z');
    $invoice = $billing->invoice($domain->id, $owner); $op = Operation::where('invoice_id',$invoice->id)->sole();
    \App\Helpers\ExtensionHelper::addPayment($invoice,null,$op->amount); $op->refresh();
    $state->expiry = '10/20/'.$year.' 5:00:00 AM'; $state->day = '10/20/'.$year;
    $state->after = ($year+1).'-10-20'; $state->orderYear = (string) $year; $state->order = (string) (9002+$year-2027); $state->charge = '8.00';
    [$engine,$writer,$completion] = $cases($op,$domain,$binding,$compose,$clock,$state,$setNow,$nativeMoney);
    $state->expiry = $state->after;
    same('succeeded',$completion->run($op->id)->state,'Admitted adopted renewal still completes from original charge');
    $domain->refresh();
    same($snapshot,$domain->getRawOriginal('snapshot'),'Deadline paths retain original adoption snapshot bytes');
    same($evidence,$adoption->fresh()->getRawOriginal('evidence'),'Deadline paths retain original adoption evidence bytes');
    same($adoption->id,$domain->adoption_id,'Original adoption pointer retained');
}
same(0,$domain->operations()->whereIn('action',['register','transfer'])->count(),'No acquisition evidence fabricated');
$nativeSuccessful = true;
echo "Adoption-rooted and completed-chain renewal deadlines passed\n";
