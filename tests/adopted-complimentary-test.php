<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adopted-renewal.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingAnchor, HoldingLocks, RenewalCycles};
extract(adoptedRenewalFixture());
$role = App\Models\Role::create(['name' => 'Adopted renewal funding', 'permissions' => ['admin.domains.manage', 'admin.domains.fund']]);
$staff->update(['role_id' => $role->id]);
$cycles = new RenewalCycles($renewBindings, $clock); $original = $domain->getRawOriginal('snapshot');
holdingRefused(fn () => $cycles->complimentary($domain->id, $owner, 1, new Money('8.00', 'USD'), 'staff_reviewed'), 'Unprivileged complimentary authority refused');
$op = $cycles->complimentary($domain->id, $staff, 1, new Money('8.00', 'USD'), 'staff_reviewed');
same('complimentary', $op->funding_basis, 'Initial adoption permits explicitly audited complimentary renewal');
same(null, $op->invoice_id, 'No complimentary acquisition invoice');
same($op->id, $cycles->complimentary($domain->id, $staff, 1, new Money('8.00', 'USD'), 'staff_reviewed')->id, 'Complimentary adoption cycle is idempotent');
$policy = $domain->fresh()->getRawOriginal('renewal_policy'); $changed = $domain->fresh()->renewal_policy; $changed['billing_anchor']['confirmed_revision'] = 2;
DB::table('domains_domains')->where('id', $domain->id)->update(['confirmed_revision' => 2, 'renewal_policy' => encrypt(json_encode($changed), false)]);
try { holdingRefused(fn () => $cycles->complimentary($domain->id, $staff, 1, new Money('8.00', 'USD'), 'staff_reviewed'), 'Idempotent complimentary result still requires rooted revision proof'); }
finally { DB::table('domains_domains')->where('id', $domain->id)->update(['confirmed_revision' => 1, 'renewal_policy' => $policy]); }
[$engine, $writer, $completion] = $compose($binding);
$clock->time = new DateTimeImmutable('2027-10-20T11:59:00Z'); $state->charge = '8.00'; Cache::flush();
same('pending', $engine->run($op->id, $writer)->state, 'First complimentary adopted renewal dispatches');
$state->expiry = $state->after;
same('succeeded', $completion->run($op->id)->state, 'Complimentary adopted renewal completes with actual charge');
$domain->refresh(); same($original, $domain->getRawOriginal('snapshot'), 'Complimentary renewal retains original holding bytes');
same('completed_operation', DB::transaction(fn () => HoldingAnchor::resolve(HoldingLocks::take($domain)))->kind, 'Complimentary completion advances the shared union');
$clock->time = new DateTimeImmutable('2028-09-29T12:00:00Z');
$first = $op->fresh()->getRawOriginal('policy'); $broken = $op->fresh()->policy; $broken['renewal']['confirmed_revision'] = 99;
DB::table('domains_operations')->where('id', $op->id)->update(['policy' => encrypt(json_encode($broken), false)]);
try { holdingRefused(fn () => $cycles->complimentary($domain->id, $staff, 1, new Money('8.00', 'USD'), 'staff_reviewed'), 'Later complimentary renewal refuses broken adoption chain'); }
finally { DB::table('domains_operations')->where('id', $op->id)->update(['policy' => $first]); }
same(1, $domain->operations()->count(), 'Broken chain creates no second obligation');
same(1, $state->writes, 'Only one audited synthetic write');
echo "Adopted complimentary funding uses original and completed union branches and refuses broken revisions.\n";
$nativeSuccessful = true;
