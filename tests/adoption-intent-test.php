<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adoption-confirm.php';
use App\Models\ServiceCancellation;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain};
extract(adoptionCommitFixture());
// Privileged fixtures represent retained native intent, not a new adoption writer.
$id = DB::table('service_cancellations')->insertGetId(['service_id' => $service->id, 'type' => 'immediate', 'reason' => 'Conflicting old cancellation', 'created_at' => now(), 'updated_at' => now()]);
$r = $reader->preview($target, $context); $money = scenarioMoney();
expect($r->code !== 'ready', 'Immediate cancellation conflict refuses preview'); same(0, Domain::count(), 'Conflicting cancellation creates no holding');
DB::table('service_cancellations')->where('id', $id)->update(['type' => 'end_of_period']);
$r = $reader->preview($target, $context); same('ready', $r->code, 'Existing end-of-period can be retained');
$a = Adoption::findOrFail($r->previewId); same(true, $a->do_not_renew, 'Existing stop cannot become renewal on');
$d = $commit->confirm($a->request_key, $a->request_digest, $staff);
same($id, (int) $a->fresh()->cancellation_id, 'Original cancellation ID reused'); same(1, ServiceCancellation::count(), 'No duplicate cancellation');
same('end_of_period', ServiceCancellation::sole()->type, 'Retained type never immediate'); same($money, scenarioMoney(), 'Existing intent does not rewrite history');
// Event-time clones and unrelated guarded objects never inherit the exact-object grant.
DB::table('domains_domains')->where('id', $d->id)->delete(); DB::table('domains_adoptions')->where('id', $a->id)->delete();
DB::table('service_cancellations')->where('id', $id)->delete(); DB::table('services')->where('id', $service->id)->update(['status' => 'pending']);
DB::table('invoices')->delete();
$seen = false; ServiceCancellation::creating(static function (ServiceCancellation $c) use (&$seen): void {
    if ($seen) { return; } $seen = true; $clone = clone $c; $clone->save();
    same('immediate', $clone->fresh()->type, 'Clone uses ordinary unpaid-cancellation guard, not adoption grant');
    throw new DomainException('Injected cross-object cancellation save');
});
$r = $reader->preview($target, new Paymenter\Extensions\Servers\Domains\Data\Adoption\StaffContext($staff->id, 'Verify isolated grant', true));
$a = Adoption::findOrFail($r->previewId); $yes = false;
try { $commit->confirm($a->request_key, $a->request_digest, $staff); } catch (DomainException) { $yes = true; }
expect($seen && $yes, 'Nested other-object cancellation aborts adoption'); same(0, Domain::count(), 'Cross-object callback rolls holding back'); same(0, ServiceCancellation::count(), 'Cross-object callback rolls cancellation back');
$nativeSuccessful = true; echo "Retained cancellation reuse, immediate conflict and exact-object grant isolation passed\n";
