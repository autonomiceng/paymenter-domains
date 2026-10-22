<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/adoption-preview.php';
require __DIR__.'/Support/lifecycle-scenarios.php';
use App\Models\{Invoice, InvoiceItem, Order, Service, ServiceCancellation};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain, Operation, Price};
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionCommit, HoldingAdoptionSchema, NativeDomainGuard, HolderPricing};
config(['app.url' => 'https://domains-tests.invalid']);
extract(adoptionFixture(Environment::Live)); HoldingAdoptionSchema::install(); \Paymenter\Extensions\Servers\Domains\Services\HistoryLinkSchema::install();
$permissions = [Staff::VIEW, Staff::ADOPT_PREVIEW, Staff::ADOPT, 'admin.domains.manage', 'admin.services.update'];
DB::table('roles')->where('id', $staff->role_id)->update(['permissions' => json_encode($permissions)]);
$guard = new NativeDomainGuard(new HolderPricing($prices), $clock); $guard->boot();
$commit = new AdoptionCommit($mapping, $bindings, $clock);
$preview = function () use ($reader, $target, $context): Adoption {
    $r = $reader->preview($target, $context); same('ready', $r->code, 'Fresh live preview ready: '.$r->code);
    return Adoption::findOrFail($r->previewId);
};
$confirm = fn ($a, $actor = null, $digest = null) => $commit->confirm($a->request_key, $digest ?? $a->request_digest, $actor ?? $staff);
$refuse = function (callable $call, string $label): void {
    $money = scenarioMoney(); $domains = Domain::count(); $ops = Operation::count(); $c = ServiceCancellation::count();
    $yes = false; try { $call(); } catch (DomainException|LogicException $e) { $yes = true; }
    expect($yes, $label); same($money, scenarioMoney(), $label.' retains finances');
    same([$domains, $ops, $c], [Domain::count(), Operation::count(), ServiceCancellation::count()], $label.' no authority or cancellation');
};
// Unrelated native invoices must not change the target identity capture.
$hosting = commerceHosting(); $hostOrder = Order::create(['user_id' => $owner->id, 'currency_code' => 'USD']);
$hostService = Service::create(['user_id' => $owner->id, 'order_id' => $hostOrder->id, 'product_id' => $hosting->id,
    'plan_id' => $hosting->plans()->sole()->id, 'quantity' => 1, 'price' => '5.00', 'currency_code' => 'USD', 'status' => 'active']);
$hostInvoice = Invoice::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'paid']);
InvoiceItem::create(['invoice_id' => $hostInvoice->id, 'reference_type' => $hostService->getMorphClass(), 'reference_id' => $hostService->id, 'description' => 'Unrelated hosting', 'quantity' => 1, 'price' => '5.00']);
$a = $preview(); $refuse(fn () => $confirm($a), 'Activation defaults OFF');
config(['domains.adoption.enabled' => true]);
// A valid preview cannot acquire a first holding for an owner with deletion requested.
$intentTable=\Paymenter\Extensions\Servers\Domains\Services\UserDeletionSchema::TABLE;
DB::table($intentTable)->insert(['user_id'=>$owner->id,'created_at'=>now()]);$before=scenarioMoney();$blocked=false;
try{$confirm($a);}catch(DomainException $e){same(\Paymenter\Extensions\Servers\Domains\Services\NativeUserDeletion::PENDING,$e->getMessage(),'Exact adoption deletion refusal');$blocked=true;}
finally{DB::table($intentTable)->where('user_id',$owner->id)->delete();}
expect($blocked,'Marked owner cannot confirm valid adoption');same(0,Domain::count(),'Marked adoption creates no holding');same($before,scenarioMoney(),'Marked adoption preserves native money');

$refuse(fn () => $confirm($a, $other), 'Another actor cannot use request key');
$refuse(fn () => $confirm($a, digest: str_repeat('f', 64)), 'Digest change refused');
$clock->time = $a->observed_at->toDateTimeImmutable()->modify('+120 seconds');
$refuse(fn () => $confirm($a), 'Evidence expires at 120 seconds');
$clock->time = $a->observed_at->toDateTimeImmutable()->modify('-1 second');
$refuse(fn () => $confirm($a), 'Future evidence refused');
$clock->time = new DateTimeImmutable('2026-10-19T00:00:00Z');
foreach ($permissions as $permission) {
    DB::table('roles')->where('id', $staff->role_id)->update(['permissions' => json_encode(array_values(array_diff($permissions, [$permission])))]);
    $refuse(fn () => $confirm($a), 'Revoked '.$permission.' refused');
    DB::table('roles')->where('id', $staff->role_id)->update(['permissions' => json_encode($permissions)]);
}
foreach ([['orders', $order->id, 'user_id', $other->id], ['services', $service->id, 'user_id', $other->id],
    ['services', $service->id, 'order_id', null], ['properties', $property->id, 'value', 'other-placeholder.com'],
    ['services', $service->id, 'status', 'suspended'], ['services', $service->id, 'quantity', 2], ['services', $service->id, 'expires_at', '2027-10-20'],
    ['domains_prices', Price::where('server_id', $tld->server_id)->where('action', 'renew')->sole()->id, 'revision', 999]] as [$table, $id, $field, $value]) {
    $old = DB::table($table)->where('id', $id)->value($field); DB::table($table)->where('id', $id)->update([$field => $value]);
    $refuse(fn () => $confirm($a), 'Changed '.$table.'.'.$field.' refused');
    DB::table($table)->where('id', $id)->update([$field => $old]);
}
$settings = $service->product->server->settings()->where('key', 'domains_credentials')->sole();
$old = $settings->getRawOriginal('value'); DB::table('settings')->where('id', $settings->id)->update(['value' => 'unreadable']);
$refuse(fn () => $confirm($a), 'Credential revision refused'); DB::table('settings')->where('id', $settings->id)->update(['value' => $old]);
config(['app.url' => 'https://different.invalid']); $refuse(fn () => $confirm($a), 'Copied site refused'); config(['app.url' => 'https://domains-tests.invalid']);
$foreignInvoice = Invoice::create(['user_id' => $other->id, 'currency_code' => 'USD', 'status' => 'paid']);
$foreignItem = InvoiceItem::create(['invoice_id' => $foreignInvoice->id, 'reference_type' => $service->getMorphClass(), 'reference_id' => $service->id, 'description' => 'Foreign owner history', 'quantity' => 1, 'price' => '1.00']);
$refuse(fn () => $confirm($a), 'Foreign invoice added after preview refused');
DB::table('invoice_items')->where('id', $foreignItem->id)->delete(); DB::table('invoices')->where('id', $foreignInvoice->id)->delete();
$lateInvoice = Invoice::create(['user_id' => $other->id, 'currency_code' => 'USD', 'status' => 'pending']);
$injectDebt = true;
DB::listen(static function ($query) use (&$injectDebt, $lateInvoice, $service): void {
    if ($injectDebt && DB::transactionLevel() === 1 && str_starts_with(strtolower($query->sql), 'select * from "invoices"')) {
        $injectDebt = false;
        InvoiceItem::create(['invoice_id' => $lateInvoice->id, 'reference_type' => $service->getMorphClass(), 'reference_id' => $service->id, 'description' => 'Serialized coverage change', 'quantity' => 1, 'price' => '1.00']);
    }
});
$refuse(fn () => $confirm($a), 'Related invoice coverage changing after invoice read aborts before service locks');
same(false, $injectDebt, 'Serialized debt interleaving was exercised');
DB::table('invoices')->where('id', $lateInvoice->id)->delete();
$collision = adoptionPendingFixture($hostService, $binding, ['domain' => 'other-placeholder.com', 'sld' => 'other-placeholder', 'reservation_name' => 'other-placeholder.com', 'provider_domain_id' => '123']);
$refuse(fn () => $confirm($a), 'Provider identity collision added after preview refused');
DB::table('domains_domains')->where('id', $collision->id)->delete();
$payloads = $wire->payloads;
foreach ($payloads as $key => $value) { $wire->payloads[$key] = str_replace(['10/19/2027', '11:14:00 PM'], ['10/18/2026', '5:00:30 PM'], $value); }
$expiring = $preview(); $clock->time = $clock->time->modify('+60 seconds');
$refuse(fn () => $confirm($expiring), 'Registrar expiry crossing inside preview lifetime refused');
$clock->time = new DateTimeImmutable('2026-10-19T00:00:00Z'); $wire->payloads = $payloads;
$before = scenarioMoney(); $calls = count($wire->calls); $ops = Operation::count(); $nativeIds = [$order->id, $service->id, $owner->id, $product->id, $plan->id];
$queries = []; DB::listen(function ($q) use (&$queries): void { $queries[] = strtolower($q->sql); });
$d = $confirm($a);
same('adopted', $d->provenance, 'Fresh adoption provenance'); same('active', $d->status, 'Adopted active');
same(1, $d->authority_generation, 'New holding starts at positive generation one'); same(1, $d->confirmed_revision, 'First confirmed revision');
same($a->id, $d->adoption_id, 'Two-way adoption pointer'); same($d->id, (int) $a->fresh()->domain_id, 'Reverse pointer');
same('2027-10-20', $d->provider_expires_at->format('Y-m-d'), 'Verified UTC expiry');
same('example.com', $d->reservation_name, 'Globally reserved'); same(true, $d->billing_managed, 'Managed holding');
same(null, $service->fresh()->expires_at, 'Native expiry remains null'); same('active', $service->fresh()->status, 'Native active projection');
same($a->evidence['retail']['annual_gross'], number_format((float) $service->fresh()->price, 2, '.', ''), 'Annual current retail projected');
same($nativeIds, [$service->fresh()->order_id, $d->service_id, $d->user_id, $service->fresh()->product_id, $service->fresh()->plan_id], 'All native IDs retained');
same($before, scenarioMoney(), 'Adoption creates no charge, payment, invoice, credit or history rewrite');
same($ops, Operation::count(), 'No operation synthesized'); same($calls, count($wire->calls), 'Confirm makes no provider call');
expect(!isset($d->snapshot['checkout']), 'No fabricated checkout'); same('standard', $d->price_mode, 'No complimentary authority');
$first = static function (string $table) use (&$queries): int {
    foreach ($queries as $i => $sql) { if (str_starts_with($sql, 'select *') && str_contains($sql, 'from "'.$table.'"')) { return $i; } }
    throw new RuntimeException('Missing ordered lock query '.$table);
};
// SQLite strips FOR UPDATE: assert the shared prefix's retained-row query order, not concurrent locking.
expect($first('orders') < $first('services'), 'Native orders lock before services');
expect($first('products') < $first('domains_domains'), 'Native products lock before domains');
$raw = $d->getRawOriginal(); $authority = scenarioAuthority($d); $savedPrice = $service->fresh()->price;
$rate = Price::where('server_id', $tld->server_id)->where('action', 'renew')->sole();
DB::table('domains_prices')->where('id', $rate->id)->update(['retail' => '19.99', 'revision' => 999]);
$clock->time = $clock->time->modify('+1 day');
same($d->id, $confirm($a)->id, 'Committed replay returns original holding after preview expiry');
// Serialized waiting-call model: discovery returns the old preview while the mutex-protected row is committed.
$staleSeed = true;
Adoption::retrieved(static function (Adoption $row) use (&$staleSeed, $a): void {
    if ($staleSeed && DB::transactionLevel() === 0 && (int) $row->id === (int) $a->id) {
        $staleSeed = false; $row->setRawAttributes([...$row->getAttributes(), 'state' => 'preview', 'domain_id' => null], true);
    }
});
same($d->id, $confirm($a)->id, 'Waiting confirmation with stale discovery returns locked original');
same($authority, scenarioAuthority($d), 'Replay never bumps generation or rewrites evidence');
same($savedPrice, $service->fresh()->price, 'Replay never reprices native service');
same($before, scenarioMoney(), 'Replay does not fabricate finances');
DB::table('domains_prices')->where('id', $rate->id)->update(['retail' => $rate->getRawOriginal('retail'), 'revision' => $rate->revision]);
$clock->time = new DateTimeImmutable('2026-10-19T00:00:00Z');
same('competing_holding', $reader->preview($target, $context)->code, 'New key cannot readopt an adopted name');
// Disposable conversion: preserve the pending row's IDs and unreserved state through exact promotion.
DB::table('domains_domains')->where('id', $d->id)->delete();
DB::table('services')->where('id', $service->id)->update(['status' => 'pending']);
$pending = adoptionPendingFixture($service, $binding, ['reservation_name' => null]);
$a2 = $preview(); $before = scenarioMoney();
$late = true; Service::updated(static function (Service $s) use (&$late): void { if ($late) { $late = false; throw new DomainException('Injected native projection failure'); } });
$refuse(fn () => $confirm($a2), 'Late projection failure rolls whole adoption back');
same('preview', $a2->fresh()->state, 'Rollback retains preview'); same(null, $pending->fresh()->adoption_id, 'Rollback retains pending proof absence');
same(null, $pending->fresh()->reservation_name, 'Rollback does not acquire pending reservation');
$promoted = $confirm($a2); same($pending->id, $promoted->id, 'Pending ID preserved');
same([$service->id, $owner->id, $binding->serverId, $binding->fingerprint], [$promoted->service_id, $promoted->user_id, $promoted->server_id, $promoted->account_fingerprint], 'Pending identity preserved');
same(2, $promoted->authority_generation, 'Pending generation advances once'); same($before, scenarioMoney(), 'Pending adoption never touches finances');
// Retained issue-window and price flags have a native staff reader, never raw provider text.
staffPanel(); \Illuminate\Support\Facades\Auth::login($staff);
$promoted->update(['observation' => [...($promoted->observation ?? []), 'adoption_review' => ['inside_issue_window' => true, 'near_due' => false, 'price' => 'PRIVATE-PROVIDER-ALERT']]]);
$notes = \Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::adoptionReview($promoted->fresh());
expect(str_contains(implode(' ', $notes), 'apply available credit immediately'), 'Billing-window staff alert is readable');
expect(!str_contains(implode(' ', $notes), 'PRIVATE-PROVIDER-ALERT'), 'Staff alert uses safe price labels');
\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
\Livewire\Livewire::actingAs($staff)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class, ['record' => $promoted->id])
    ->assertSee('Adoption needs review')->assertSee('Ordinary billing may issue an invoice');
$promoted->update(['observation' => ['adoption_review' => ['near_due' => true]]]);
expect(str_contains(implode(' ', \Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::adoptionReview($promoted->fresh())), 'cutoff was reached'), 'Reached-cutoff staff alert is readable');
$nativeSuccessful = true;
echo "Fresh adoption, stale evidence, locked identities, replay and whole-transaction rollback passed\n";
