<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adopted-renewal.php';
require_once __DIR__.'/Support/fixture-cases.php';
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{HoldingAnchor, HoldingLocks, InvoiceBinding, RenewalCycles};
// Every case starts from the same committed live adoption with no acquisition history. The retained-history mode
// stays in adopted-renewal-history-test.php: its proved-unsent row must exist before the adoption preview reads it.
$fixture=adoptedRenewalFixture(); extract($fixture);
// Pin each case to the prepared adoption and Carbon clocks explicitly, whatever a reset restores.
$pinned=[$clock->time,Carbon\Carbon::getTestNow(),Carbon\CarbonImmutable::getTestNow()];
$pin=static function()use($clock,$pinned):void{[$clock->time,$carbon,$immutable]=$pinned;Carbon\Carbon::setTestNow($carbon);Carbon\CarbonImmutable::setTestNow($immutable);};
$fixed=[...$fixture,'pin'=>$pin];

// Two paid renewal cycles complete on the adoption anchor and keep the original provenance bytes.
$cases['renewal']=static function()use($fixed):void {extract($fixed);
    $pin();
    $original = $domain->getRawOriginal('snapshot'); $adoptionBytes = $adoption->getRawOriginal('evidence');
    same(0, $domain->operations()->count(), 'Adoption has no fabricated acquisition');
    same(null, $domain->snapshot['checkout'] ?? null, 'Adoption has no checkout');
    $domain->update(['observation' => ['ordinary' => true], 'last_synced_at' => $clock->now()]);
    same(1, $domain->fresh()->confirmed_revision, 'Observation metadata does not advance confirmed authority');
    same('adoption', DB::transaction(fn () => HoldingAnchor::resolve(HoldingLocks::take($domain)))->kind, 'Initial union branch');
    [$engine, $writer, $completion] = $compose($binding);
    foreach ([2027, 2028] as $year) {
        $clock->time = new DateTimeImmutable($year.'-09-29T12:00:00Z');
        $observeRenewal();
        $invoice = $billing->invoice($domain->id, $owner); $op = Operation::where('invoice_id', $invoice->id)->sole();
        same('renew', $op->action, 'Only renewal obligation issued');
        same(null, $invoice->items()->sole()->reference_id, 'Renewal native line has no acquisition reference');
        same(1 + $year - 2027, $op->policy['renewal']['confirmed_revision'], 'Debt freezes verified chain revision');
        \App\Helpers\ExtensionHelper::addPayment($invoice, null, $op->amount);
        $state->expiry = '10/20/'.$year.' 5:00:00 AM'; $state->day = '10/20/'.$year; $state->after = ($year + 1).'-10-20';
        $state->orderYear = (string) $year; $state->order = (string) (9002 + $year - 2027); $state->charge = '8.00';
        $clock->time = new DateTimeImmutable($year.'-10-20T11:59:00Z'); Cache::flush();
        same('pending', $engine->run($op->id, $writer)->state, 'Funded adopted renewal executes once');
        $state->expiry = $state->after;
        same('succeeded', $completion->run($op->id)->state, 'Original charge and full added year complete renewal');
        $domain->refresh();
        same($state->after, $domain->provider_expires_at->format('Y-m-d'), 'Confirmed expiry advances');
        same(2 + $year - 2027, $domain->confirmed_revision, 'Verified renewal advances one revision');
        same('completed_operation', DB::transaction(fn () => HoldingAnchor::resolve(HoldingLocks::take($domain)))->kind, 'Completed union branch');
        same($original, $domain->getRawOriginal('snapshot'), 'Original adoption snapshot bytes retained');
        same($adoptionBytes, $adoption->fresh()->getRawOriginal('evidence'), 'Original adoption evidence bytes retained');
        same($adoption->id, $domain->adoption_id, 'Original adoption pointer retained');
        same(null, $domain->service->fresh()->expires_at, 'Native expiry stays null');
        $sealed = $op->fresh()->getRawOriginal('policy'); $changed = $op->fresh()->policy; $changed['renewal']['confirmed_revision'] = 99;
        DB::table('domains_operations')->where('id', $op->id)->update(['policy' => encrypt(json_encode($changed), false)]);
        try { holdingRefused(fn () => DB::transaction(fn () => HoldingAnchor::resolve(HoldingLocks::take($domain))), 'Completed state cannot bypass prior revision chain'); }
        finally { DB::table('domains_operations')->where('id', $op->id)->update(['policy' => $sealed]); }
    }
    // Each Live renewal confirmed by its own order calibrates the bill clock after its completion, without delaying it.
    expect(\Paymenter\Extensions\Servers\Domains\Services\EnomBillZones::measured($binding) !== null, 'Live renewals measure the account bill zone');
    same(2, $state->writes, 'Exactly two synthetic renewal writes');
    same(0, $domain->operations()->whereIn('action', ['register', 'transfer'])->count(), 'No acquisition operation synthesized');
};

// Audited complimentary funding uses the original and completed union branches and refuses broken revisions.
$cases['complimentary']=static function()use($fixed):void {extract($fixed);
    $pin();
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
};

// Link, environment, identity, revision, expiry, encryption, staff review and acquisition refusals.
$cases['refusals']=static function()use($fixed):void {extract($fixed);
    $pin();
    $assert = static function (string $table, int $id, array $change, string $label) use ($billing, $domain, $owner, $state): void {
        $before = (array) DB::table($table)->where('id', $id)->first();
        DB::table($table)->where('id', $id)->update($change);
        try { holdingRefused(fn () => $billing->invoice($domain->id, $owner), $label); }
        finally { DB::table($table)->where('id', $id)->update($before); }
        same([], $state->calls, $label.' has no provider reads');
        same(0, $state->writes, $label.' has no provider writes');
        same(0, $domain->operations()->count(), $label.' has no renewal obligation');
    };
    $assert('domains_adoptions', $adoption->id, ['domain_id' => $domain->id + 1], 'One-way adoption reverse link');
    $assert('domains_domains', $domain->id, ['adoption_id' => null], 'One-way domain pointer');
    $assert('domains_adoptions', $adoption->id, ['environment' => 'test'], 'TEST evidence cannot anchor live');
    $assert('domains_adoptions', $adoption->id, ['do_not_renew' => true, 'cancellation_id' => 999], 'Unbound cancellation intent cannot grant renewal');
    $assert('domains_domains', $domain->id, ['account_fingerprint' => str_repeat('e', 64)], 'Stable fingerprint mismatch');
    $assert('domains_domains', $domain->id, ['authority_generation' => 0], 'Zero authority generation');
    $assert('domains_domains', $domain->id, ['confirmed_revision' => 0], 'Stale revision');
    $assert('domains_domains', $domain->id, ['confirmed_revision' => 2], 'Unconfirmed revision');
    $assert('domains_domains', $domain->id, ['provider_expires_at' => '2027-10-21'], 'Current expiry mismatch');
    $assert('orders', $order->id, ['user_id' => $other->id], 'Locked order owner mismatch');
    $assert('services', $service->id, ['user_id' => $other->id], 'Locked service owner mismatch');
    $anotherOrder = App\Models\Order::create(['user_id' => $owner->id, 'currency_code' => 'USD']);
    $assert('services', $service->id, ['order_id' => $anotherOrder->id], 'Changed service order relation');
    $assert('domains_domains', $domain->id, ['user_id' => $other->id], 'Ledger owner mismatch');
    $assert('domains_adoptions', $adoption->id, ['evidence' => 'not-ciphertext'], 'Undecryptable adoption evidence');
    $assert('domains_domains', $domain->id, ['snapshot' => encrypt(json_encode([]), false)], 'Adoption without holding snapshot');
    $assert('domains_domains', $domain->id, ['provider_domain_id' => '999'], 'Provider identity mismatch');
    // Even a self-consistent committed cancellation intent needs the A3 cancellation qualification.
    $beforeAdoption = (array) DB::table('domains_adoptions')->where('id', $adoption->id)->first();
    $beforeDomain = (array) DB::table('domains_domains')->where('id', $domain->id)->first();
    $intent = $adoption->evidence; $intent['do_not_renew'] = true; $intent['requested_do_not_renew'] = true;
    $digest = \Paymenter\Extensions\Servers\Domains\Holding\Adoption\AdoptionMapping::stamp($intent);
    $holding = $domain->snapshot; $holding['holding']['request_digest'] = $digest;
    DB::table('domains_adoptions')->where('id', $adoption->id)->update(['do_not_renew' => true, 'cancellation_id' => 999,
        'evidence' => encrypt(json_encode($intent), false), 'request_digest' => $digest]);
    DB::table('domains_domains')->where('id', $domain->id)->update(['snapshot' => encrypt(json_encode($holding), false)]);
    try { holdingRefused(fn () => $billing->invoice($domain->id, $owner), 'Consistent do-not-renew adoption cannot grant renewal before cancellation qualification'); }
    finally {
        DB::table('domains_adoptions')->where('id', $adoption->id)->update($beforeAdoption);
        DB::table('domains_domains')->where('id', $domain->id)->update($beforeDomain);
    }
    // The union cannot trust matching current labels after an unverified revision edit.
    $policy = $domain->renewal_policy; $policy['billing_anchor']['confirmed_revision'] = 2;
    $assert('domains_domains', $domain->id, ['confirmed_revision' => 2, 'renewal_policy' => encrypt(json_encode($policy), false)], 'Revision and anchor labels without completion proof');
    \Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
    Auth::login($staff);
    $raw = $adoption->getRawOriginal('evidence'); DB::table('domains_adoptions')->where('id', $adoption->id)->update(['evidence' => 'not-ciphertext']);
    try { $facts = DomainFacts::for($domain->fresh()); expect(isset($facts['Registrar']) && !str_contains(json_encode($facts), 'not-ciphertext'), 'Unreadable proof never breaks or leaks into staff facts'); }
    finally { DB::table('domains_adoptions')->where('id', $adoption->id)->update(['evidence' => $raw]); }
    // Adversarial native objects satisfy every old financial/checkout identity predicate.
    // Only the explicit adoption exclusion must reject this fabricated acquisition evidence.
    $invoice = new App\Models\Invoice(['user_id' => $owner->id, 'currency_code' => 'USD']); $invoice->id = 7001;
    $item = new App\Models\InvoiceItem(['invoice_id' => $invoice->id, 'reference_type' => $service->getMorphClass(),
        'reference_id' => $service->id, 'quantity' => 1, 'price' => '10.00']); $item->id = 7002;
    $c = ['user_id' => $owner->id, 'order_id' => $order->id, 'service_id' => $service->id, 'product_id' => $product->id,
        'annual_plan_id' => $plan->id, 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id, 'name' => $domain->domain,
        'term' => 1, 'action' => 'register', 'currency' => 'USD', 'total' => '10.00'];
    $op = new Operation(['domain_id' => $domain->id, 'service_id' => $service->id, 'server_id' => $domain->server_id,
        'registrar_key' => $domain->registrar_key, 'account_fingerprint' => $domain->account_fingerprint, 'authority_generation' => 2,
        'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id, 'action' => 'register', 'term' => 1, 'amount' => '10.00', 'currency' => 'USD', 'policy' => ['checkout' => $c]]);
    $forged = clone $domain; $forged->snapshot = [...$domain->snapshot, 'checkout' => $c];
    $purchaseShape = clone $forged; $purchaseShape->adoption_id = null; $purchaseShape->provenance = 'purchased'; $purchaseShape->snapshot = ['checkout' => $c];
    InvoiceBinding::requireIdentity($op, $purchaseShape, $service->fresh(), $invoice, $item, $product);
    holdingRefused(fn () => InvoiceBinding::requireIdentity($op, $forged, $service->fresh(), $invoice, $item, $product), 'Adoption cannot satisfy purchase guard even with forged checkout objects');
    same(0, $domain->operations()->count(), 'No acquisition or renewal fabricated by refusals');
};

nativeFixtureCases($cases,array_intersect_key($fixture,array_flip(['clock','state','domain','adoption','service','order','owner','other','staff','binding','billing'])));
echo "Adopted renewal cycles, complimentary funding and refusals passed.\n";
$nativeSuccessful = true;
