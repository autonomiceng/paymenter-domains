<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/adoption-preview.php';
require_once __DIR__.'/Support/fixture-cases.php';
use App\Models\{Invoice, Order, Server, Service, ServiceCancellation};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};
use Paymenter\Extensions\Servers\Domains\Data\Adoption\{StaffContext, Target};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Adoption, Domain, Operation, Price};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionClosureKind, AcquisitionResultSchema, OperationWriter, Transition};
// Preview cases share one adoption fixture; each case restores rows, schema, listeners, container, config,
// clocks and fake ports before the next.
extract($fixture=adoptionFixture());
// Adoption preview mapping, separation and encrypted evidence scenarios.
$cases['mapping']=static function()use($fixture):void {extract($fixture);
$nativeBefore = [$service->fresh()->getAttributes(), $order->fresh()->getAttributes(), Invoice::count(), Domain::count(), Operation::count()];
$review = $reader->preview($target, $context);
same('ready', $review->code, 'Verified TEST mapping eligible for rehearsal');
same(true, $review->details['test_only'], 'TEST visibly flagged'); same(false, $review->jsonSerialize()['live_anchor_eligible'], 'TEST grants no live authority');
same(false, $review->jsonSerialize()['can_confirm'], 'No adoption commit enabled');
same('2027-10-13', $review->details['renewal_due'], 'Default seven day due cutoff');
$row = Adoption::findOrFail($review->previewId);
same('preview', $row->state, 'Only preview row persisted'); same('test', $row->environment, 'Evidence and row bind TEST');
same('123', $row->evidence['provider_domain_id'], 'Exact parsed identity saved encrypted');
same(0, $row->evidence['prior_generation'], 'No fabricated holding'); same(null, $row->authority_generation, 'No committed generation');
same($context->reason, $row->evidence['reason'], 'Full intent in digest evidence');
same([Staff::VIEW => true, Staff::ADOPT_PREVIEW => true], $row->evidence['permission_snapshot'], 'Exact required permission snapshot retained');
same(64, strlen($row->evidence['capture_digest']), 'Pre-read revision digest retained');
same(64, strlen($row->evidence['intent_digest']), 'Pre-read intent digest retained');
same(hash('sha256', json_encode($row->evidence, JSON_THROW_ON_ERROR)), $row->request_digest, 'Digest covers complete normalized intent');
$raw = DB::table('domains_adoptions')->where('id', $row->id)->value('evidence');
expect(!str_contains($raw, 'example.com') && !str_contains($raw, 'provider_domain_id'), 'Evidence encrypted at rest');
$safe = json_encode($review);
foreach (['account-enom', 'synthetic-secret', 'provider_domain_id', 'request_digest', 'account_fingerprint'] as $secret) { expect(!str_contains($safe, $secret), 'Safe DTO excludes '.$secret); }
same($nativeBefore, [$service->fresh()->getAttributes(), $order->fresh()->getAttributes(), Invoice::count(), Domain::count(), Operation::count()], 'Preview cannot mutate native or holding authority');
expect(isset(Staff::permissions()[Staff::ADOPT_PREVIEW]), 'Dedicated default-deny permission registered');
$limited = staffUser([Staff::VIEW]); $count = count($wire->calls);
same('permission_denied', $reader->preview($target, new StaffContext($limited->id, 'Reason'))->code, 'View permission alone insufficient');
same($count, count($wire->calls), 'Permission refusal zero wire');
foreach ([
    new Target('example.com', $service->id, $other->id, $tld->server_id, Environment::Test),
    new Target('other-placeholder.com', $service->id, $owner->id, $tld->server_id, Environment::Test),
    new Target('example.com', $service->id, $owner->id, $tld->server_id, Environment::Live),
    new Target('example.com', $service->id, $owner->id, $tld->server_id + 100, Environment::Test),
] as $bad) { $before = count($wire->calls); expect($reader->preview($bad, $context)->code !== 'ready', 'Wrong target refused'); same($before, count($wire->calls), 'Wrong local identity zero wire'); }
// The supported model writer must still refuse; only the retained-data fixture below may drift.
$orderOwnerRefused = false;
try { $order->update(['user_id' => $other->id]); }
catch (DomainException $error) {
    $orderOwnerRefused = true;
    same('This change affects managed domain identity and was refused.', $error->getMessage(), 'Native order identity guard remains active');
}
expect($orderOwnerRefused, 'Native model writer cannot change the mapped order owner');
$order->refresh();
// Privileged retained-row drift isolates adoption validation from the independent native write guard.
DB::table('orders')->where('id',$order->id)->update(['user_id'=>$other->id]);
same('mapping_unconfirmed', $reader->preview($target, $context)->code, 'Native order owner must agree'); DB::table('orders')->where('id',$order->id)->update(['user_id'=>$owner->id]);
foreach (['true' => 'auto_renew_on', 'unknown' => 'auto_renew_unknown'] as $flag => $code) {
    $wire->payloads['GetRenew'] = '<auto-renew>'.$flag.'</auto-renew>';
    $refused = $reader->preview($target, $context); same($code, $refused->code, 'Fresh provider refusal persists safely');
    same('refused', Adoption::findOrFail($refused->previewId)->state, 'Refusal retained');
}
$wire->payloads['GetRenew'] = '<auto-renew>false</auto-renew>';
$wire->payloads['GetDomainExp'] = '<ExpirationDate>10/20/2027 11:14:00 PM</ExpirationDate>';
same('expiry_disagrees', $reader->preview($target, $context)->code, 'Expiry disagreement remains pending');
$wire->payloads['GetDomainExp'] = '<ExpirationDate>10/19/2027 11:14:00 PM</ExpirationDate>';
foreach (['owner', 'role', 'property', 'price', 'site'] as $change) {
    $armed = true;
    $originalPrice = Price::where('action', 'renew')->where('server_id', $tld->server_id)->sole()->getRawOriginal();
    $wire->during = function () use (&$armed, $change, $service, $other, $staff, $property, $originalPrice): void {
        expect(DB::transactionLevel() === 0, 'Network outside locks');
        if (!$armed) { return; } $armed = false;
        match ($change) {
            'owner' => DB::table('services')->where('id', $service->id)->update(['user_id' => $other->id]),
            'role' => DB::table('roles')->where('id', $staff->role_id)->update(['permissions' => json_encode([Staff::VIEW])]),
            'property' => DB::table('properties')->where('id', $property->id)->update(['value' => 'other-placeholder.com']),
            'price' => DB::table('domains_prices')->where('id', $originalPrice['id'])->update(['revision' => $originalPrice['revision'] + 1]),
            'site' => config(['app.url' => 'http://changed.invalid']),
        };
    };
    $before = Adoption::count(); same('changed', $reader->preview($target, $context)->code, 'Mid-read '.$change.' change discards proof'); same($before, Adoption::count(), 'Changed proof never persisted as fresh preview');
    DB::table('services')->where('id', $service->id)->update(['user_id' => $owner->id]);
    DB::table('roles')->where('id', $staff->role_id)->update(['permissions' => json_encode([Staff::VIEW, Staff::ADOPT_PREVIEW])]);
    DB::table('properties')->where('id', $property->id)->update(['value' => 'example.com']);
    DB::table('domains_prices')->where('id', $originalPrice['id'])->update(['revision' => $originalPrice['revision']]);
    config(['app.url' => 'http://domains-tests.invalid']);
}
$wire->during = null;
$pending = adoptionPendingFixture($service, $binding, ['provider_domain_id' => '123']);
same('ready', $reader->preview($target, $context)->code, 'Reviewed pending row agrees with fresh identity and expiry');
DB::table('domains_domains')->where('id', $pending->id)->update(['reservation_name' => null]);
$unreserved = $reader->preview($target, $context); same('ready', $unreserved->code, 'Converted null reservation remains previewable');
same($pending->id, Adoption::findOrFail($unreserved->previewId)->domain_id, 'Preview retains original pending row identity');
same(null, $pending->fresh()->reservation_name, 'Preview never acquires a reservation');
$peerService = Service::withoutEvents(fn()=>Service::create(['user_id' => $owner->id, 'order_id' => $order->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
    'quantity' => 1, 'price' => '10.00', 'currency_code' => 'USD', 'status' => 'pending']));
$collision = adoptionPendingFixture($peerService, $binding, ['domain' => 'other-placeholder.com', 'sld' => 'other-placeholder']);
$count = count($wire->calls); same('competing_holding', $reader->preview($target, $context)->code, 'Another retained reservation still blocks'); same($count, count($wire->calls), 'Reservation collision zero wire');
DB::table('domains_domains')->where('id', $collision->id)->delete();
foreach (['service_id' => $peerService->id, 'user_id' => $other->id, 'account_fingerprint' => str_repeat('a', 64)] as $key => $bad) {
    $old = $pending->getRawOriginal($key); DB::table('domains_domains')->where('id', $pending->id)->update([$key => $bad]);
    $count = count($wire->calls); same('mapping_unconfirmed', $reader->preview($target, $context)->code, 'Wrong pending '.$key.' refused'); same($count, count($wire->calls), 'Wrong pending binding zero wire');
    DB::table('domains_domains')->where('id', $pending->id)->update([$key => $old]);
}
DB::table('domains_domains')->where('id', $pending->id)->update(['provider_domain_id' => '456']);
same('identity_unconfirmed', $reader->preview($target, $context)->code, 'Stored pending provider ID must agree');
DB::table('domains_domains')->where('id', $pending->id)->update(['renewal_policy' => encrypt(json_encode(['source_expiry' => '2027-10-21']), false)]);
same('identity_unconfirmed', $reader->preview($target, $context)->code, 'Identity refusal takes precedence over source expiry mismatch');
DB::table('domains_domains')->where('id', $pending->id)->update(['provider_domain_id' => '123', 'renewal_policy' => encrypt(json_encode(['source_expiry' => '2027-10-21']), false)]);
same('expiry_disagrees', $reader->preview($target, $context)->code, 'Converted source expiry must agree even when provider expiry is null');
DB::table('domains_domains')->where('id', $pending->id)->update(['renewal_policy' => encrypt(json_encode(['source_expiry' => null]), false)]);
$count = count($wire->calls); same('expiry_unconfirmed', $reader->preview($target, $context)->code, 'Missing converted source date requires separate qualification'); same($count, count($wire->calls), 'Unknown source date zero wire');
DB::table('domains_domains')->where('id', $pending->id)->update(['renewal_policy' => encrypt(json_encode(['source_expiry' => '2027-10-20']), false)]);
// Terminal labels never replace negative proof. These uncertain rows exist only in the disposable fixture.
foreach (['failed', 'cancelled', 'succeeded'] as $state) {
    $op = Operation::create(['domain_id' => $pending->id, 'service_id' => $service->id, 'server_id' => $binding->serverId,
        'registrar_key' => 'enom', 'account_fingerprint' => $binding->fingerprint, 'authority_generation' => 1, 'action' => 'register',
        'operation_key' => 'synthetic-uncertain-'.$state, 'term' => 1, 'currency' => 'USD', 'amount' => '10.00', 'state' => $state,
        'attempt' => ['seal' => 'sealed', 'send_state' => 'possibly_sent'], 'policy' => [], 'payload' => []]);
    $count = count($wire->calls); same('unresolved_debt', $reader->preview($target, $context)->code, 'Unproved terminal label refuses: '.$state); same($count, count($wire->calls), 'Unresolved retained attempt zero wire');
    DB::table(AcquisitionResultSchema::TABLE)->where('operation_id', $op->id)->delete();
    DB::table('domains_operations')->where('id', $op->id)->delete();
}
$unsent = Operation::create(['domain_id' => $pending->id, 'service_id' => $service->id, 'server_id' => $binding->serverId,
    'registrar_key' => 'enom', 'account_fingerprint' => $binding->fingerprint, 'authority_generation' => 1, 'action' => 'register',
    'operation_key' => 'synthetic-proved-unsent', 'term' => 1, 'currency' => 'USD', 'amount' => '10.00', 'state' => 'held',
    'policy' => [], 'payload' => []]);
DB::transaction(static function () use ($unsent, $clock): void {
    $locked = Operation::whereKey($unsent->id)->lockForUpdate()->sole();
    same(Transition::Applied, OperationWriter::at($clock->now())->recordTerminal((int) $locked->id,
        AcquisitionClosureKind::Unsent->value, [
            'operation_key' => $locked->operation_key, 'generation' => $locked->authority_generation, 'attempt_id' => null,
        ]), 'Predecessor purchase has a typed unsent terminal');
    $locked->update(['state' => 'rejected']);
});
same('ready', $reader->preview($target, $context)->code, 'Existing bound definitive-unsent proof may be reviewed without authority');
$before = count($wire->calls);
DB::transaction(function () use ($reader, $target, $context): void { try { $reader->preview($target, $context); expect(false, 'Outer transaction forbidden'); } catch (LogicException) {} });
same($before, count($wire->calls), 'No provider read under caller locks');
};
// Adoption native route, invoice history, calendar and synthetic live site guards.
$cases['guards']=static function()use($fixture):void {extract($fixture);
$run = fn () => $reader->preview($target, $context);
$diagnostics = [];
\Illuminate\Support\Facades\Log::listen(static function ($event) use (&$diagnostics): void {
    if ($event->message === 'Domains adoption preview failed.') { $diagnostics[] = $event->context; }
});
$ownerChanged = false;
$wire->during = static function () use (&$ownerChanged, $service, $other): void {
    if ($ownerChanged) { return; }
    $ownerChanged = true;
    DB::table('services')->where('id', $service->id)->update(['user_id' => $other->id]);
};
$previews = Adoption::count();
try {
    $changed = $run();
    expect($ownerChanged, 'Owner change occurred during provider readback');
    same('changed', $changed->code, 'Post-read owner loss keeps the changed refusal, got '.$changed->code);
    same([null, []], [$changed->previewId, $changed->details], 'Changed owner produces no reusable preview details');
    same($previews, Adoption::count(), 'Changed owner evidence is not retained');
    same([], $diagnostics, 'Expected post-read mapping refusal logs no private detail');
} finally {
    $wire->during = null;
    DB::table('services')->where('id', $service->id)->update(['user_id' => $owner->id]);
}
same('ready', $run()->code, 'Fresh preview recovers after owner mapping is restored');
$phase = null; $accountReads = 0; $capturedAccounts = 0; $afterRead = false; $faults = 0;
DB::connection()->beforeExecuting(static function ($sql) use (&$phase, &$accountReads, &$capturedAccounts, &$afterRead, &$faults): void {
    if (str_contains($sql, 'from "domains_accounts"')) { $accountReads++; }
    if (($phase === 'mapping' && str_starts_with($sql, 'select * from "services"'))
        || ($phase === 'binding' && str_contains($sql, 'from "domains_accounts"') && $accountReads === $capturedAccounts + 1)
        || ($phase === 'recheck' && $afterRead && str_starts_with($sql, 'select * from "services"'))) {
        $faults++; throw new RuntimeException('PRIVATE-database-detail');
    }
});
$mapping->capture($target, $context); $capturedAccounts = $accountReads;
foreach (['mapping' => 'mapping_unconfirmed', 'binding' => 'account_unconfirmed', 'recheck' => 'changed'] as $stage => $code) {
    $phase = $stage; $accountReads = 0; $afterRead = false; $prior = count($diagnostics); $hits = $faults;
    $wire->during = static function () use (&$afterRead): void { $afterRead = true; };
    try { same($code, $run()->code, 'Unexpected '.$stage.' fault retains the fixed refusal'); }
    finally { $phase = null; $wire->during = null; }
    same($hits + 1, $faults, 'Fault was reached at '.$stage);
    same([['exception_class' => RuntimeException::class]], array_slice($diagnostics, $prior), 'Only unexpected class is logged at '.$stage);
    same('ready', $run()->code, 'Preview recovers after '.$stage.' fault');
}
$beforeDiagnostics = count($diagnostics);

foreach ([['quantity', 2], ['currency_code', 'EUR'], ['plan_id', $product->plans()->where('billing_period', 3)->sole()->id]] as [$key, $value]) {
    $old = $service->fresh()->getAttribute($key); DB::table('services')->where('id', $service->id)->update([$key => $value]);
    $before = count($wire->calls); same('mapping_unconfirmed', $run()->code, 'Non-dedicated annual mapping refused: '.$key);
    same($before, count($wire->calls), 'Invalid mapping no wire'); DB::table('services')->where('id', $service->id)->update([$key => $old]);
}
same($beforeDiagnostics, count($diagnostics), 'Expected mapping refusals do not log infrastructure faults');
DB::table('services')->where('id', $service->id)->update(['expires_at' => '2026-11-01']);
$count = count($wire->calls); same('native_expiry_present', $run()->code, 'Native cron expiry must be qualified before preview'); same($count, count($wire->calls), 'Native expiry zero wire');
DB::table('services')->where('id', $service->id)->update(['expires_at' => null]);
$transfer = $native->product($tld, 'transfer'); $transferPlan = $native->annualPlan($transfer);
DB::table('services')->where('id', $service->id)->update(['product_id' => $transfer->id, 'plan_id' => $transferPlan->id]);
$count = count($wire->calls); same('mapping_unconfirmed', $run()->code, 'Transfer route needs separate normalization'); same($count, count($wire->calls), 'Transfer route zero wire');
DB::table('services')->where('id', $service->id)->update(['product_id' => $product->id, 'plan_id' => $plan->id]);
$hostingProduct = $product->replicate(); $hostingProduct->slug = 'synthetic-hosting'; $hostingProduct->server_id = null; $hostingProduct->save();
// Historical cross-product plan is deliberate adversarial import input, not current checkout.
$hosting=Service::withoutEvents(static function()use($owner,$order,$hostingProduct,$plan){
    $row=Service::create(['user_id'=>$owner->id,'order_id'=>$order->id,'product_id'=>$hostingProduct->id,
        'plan_id'=>$plan->id,'quantity'=>1,'price'=>'10.00','currency_code'=>'USD','status'=>'active']);
    $row->properties()->create(['key'=>'domain','value'=>'example.com']);return $row;
});
same('ready', $run()->code, 'Ordinary hosting source may coexist with dedicated domain service');
$legacyServer = Server::create(['name' => 'Synthetic legacy registrar', 'extension' => 'Enom', 'type' => 'server', 'enabled' => true]);
DB::table('products')->where('id', $hostingProduct->id)->update(['server_id' => $legacyServer->id]);
$count = count($wire->calls); same('competing_holding', $run()->code, 'Another registrar extension cannot coexist'); same($count, count($wire->calls), 'Competing registrar zero wire');
foreach (['example.com.', ' EXAMPLE.COM ', ' Example.Com. '] as $spelling) {
    $hosting->properties()->where('key', 'domain')->update(['value' => $spelling]);
    $count = count($wire->calls); same('competing_holding', $run()->code, 'Canonical legacy registrar spelling competes: '.$spelling); same($count, count($wire->calls), 'Canonical competing spelling zero wire');
}
$hosting->properties()->where('key', 'domain')->update(['value' => 'example.com']);
DB::table('products')->where('id', $hostingProduct->id)->update(['server_id' => null]);

$hostingServer = Server::create(['name' => 'Synthetic hosting', 'extension' => 'SyntheticHosting', 'type' => 'server', 'enabled' => true]);
DB::table('products')->where('id', $hostingProduct->id)->update(['server_id' => $hostingServer->id]);
same('competing_holding', $run()->code, 'A second server-backed service with the same name blocks adoption');
DB::table('products')->where('id', $hostingProduct->id)->update(['server_id' => $legacyServer->id]);
same('competing_holding', $run()->code, 'A second registrar service blocks adoption');
DB::table('products')->where('id', $hostingProduct->id)->update(['server_id' => null]);
$peer = Service::withoutEvents(static fn()=>Service::create(['user_id' => $other->id, 'order_id' => $order->id, 'product_id' => $product->id,
    'plan_id' => $plan->id, 'quantity' => 1, 'price' => '10.00', 'currency_code' => 'USD', 'status' => 'pending']));
$peerProperty = Service::withoutEvents(static fn()=>$peer->properties()->create(['key' => 'domain', 'value' => 'EXAMPLE.COM']));
same('competing_holding', $run()->code, 'Other service canonical name collision refused');
DB::table('properties')->where('id',$peerProperty->id)->update(['value'=>'other-placeholder.com']);
$cancel = ServiceCancellation::create(['service_id' => $service->id, 'type' => 'immediate', 'reason' => 'Synthetic']);
same('mapping_unconfirmed', $run()->code, 'Conflicting immediate cancellation refused');
DB::table('service_cancellations')->where('id', $cancel->id)->delete();
$end = ServiceCancellation::create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'Synthetic existing DNR']);
$existing = $run(); same(true, $existing->details['do_not_renew'], 'Existing native cancellation forces effective DNR');
same(true, $existing->details['existing_do_not_renew'], 'Existing DNR displayed explicitly');
same(true, Adoption::findOrFail($existing->previewId)->do_not_renew, 'Existing DNR frozen in retained intent');
DB::table('service_cancellations')->where('id', $end->id)->delete();

$invoice = Invoice::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending', 'due_at' => '2026-10-20']);
$item = $invoice->items()->create(['quantity' => 1, 'price' => 10, 'description' => 'Synthetic historical service', 'reference_type' => $service->getMorphClass(), 'reference_id' => $service->id]);
same('unresolved_debt', $run()->code, 'Unpaid routed historical invoice blocks adoption');
// Fixture-only transition avoids running native payment listeners; no adoption workflow changes this status.
DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
$before = [DB::table('invoices')->get()->toJson(), DB::table('invoice_items')->get()->toJson(), DB::table('invoice_transactions')->get()->toJson()];
$review = $reader->preview($target, new StaffContext($staff->id, 'Reviewed invoice mapping', true));
same('ready', $review->code, 'Paid exact native service history may be reviewed');
same([['invoice_id' => $invoice->id, 'status' => 'paid']], $review->details['history'], 'History safe ID/status projection');
same([['invoice_id' => $invoice->id, 'invoice_item_id' => $item->id]], Adoption::findOrFail($review->previewId)->evidence['history_link_ids'], 'Canonical exact invoice/item pair retained');
$laterInvoice = Invoice::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending', 'due_at' => '2026-10-20']);
DB::table('invoices')->where('id', $laterInvoice->id)->update(['status' => 'paid']);
$laterItem = $laterInvoice->items()->create(['quantity' => 1, 'price' => 10, 'description' => 'Synthetic history', 'reference_type' => $service->getMorphClass(), 'reference_id' => $service->id]);
$olderLateItem = $invoice->items()->create(['quantity' => 1, 'price' => 10, 'description' => 'Synthetic older invoice late item', 'reference_type' => $service->getMorphClass(), 'reference_id' => $service->id]);
$ordered = $run();
same([['invoice_id' => $invoice->id, 'invoice_item_id' => $item->id], ['invoice_id' => $invoice->id, 'invoice_item_id' => $olderLateItem->id],
    ['invoice_id' => $laterInvoice->id, 'invoice_item_id' => $laterItem->id]], Adoption::findOrFail($ordered->previewId)->evidence['history_link_ids'], 'History canonical invoice then item order');
$before = [DB::table('invoices')->get()->toJson(), DB::table('invoice_items')->get()->toJson(), DB::table('invoice_transactions')->get()->toJson()];
same(true, $review->details['do_not_renew'], 'Do not renew intent reviewed'); same(0, ServiceCancellation::count(), 'Preview creates no cancellation');
same($before, [DB::table('invoices')->get()->toJson(), DB::table('invoice_items')->get()->toJson(), DB::table('invoice_transactions')->get()->toJson()], 'No historical status, line, payment or credit rewrite');
DB::table('invoices')->where('id', $invoice->id)->update(['user_id' => $other->id]);
same('unresolved_debt', $run()->code, 'Foreign owner invoice cannot become history'); DB::table('invoices')->where('id', $invoice->id)->update(['user_id' => $owner->id]);
$rate = \Paymenter\Extensions\Servers\Domains\Models\Price::where('server_id', $tld->server_id)->where('action', 'renew')->sole();
$offerPorts=activeFixturePorts($native);$offerQuery=new Paymenter\Extensions\Servers\Domains\Catalogue\Query($binding,['com'],'renew');
$offerRevision=fn()=>$offerPorts->readOffers($offerQuery,Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision;
$retained=$tld->fresh()->catalogue_retained_annual_retail;
$offerPorts->apply(Paymenter\Extensions\Servers\Domains\Catalogue\Edit::retainRenewal($binding,'com','1.00'),$offerRevision());
$belowCost = $run();
same('below_cost', $belowCost->details['price_notice'], 'Below-cost retail is reviewed without a floor');
same('1.00', $belowCost->details['annual_charge'], 'Reviewed annual charge preserves the below-cost edit');
same('1.00', Adoption::findOrFail($belowCost->previewId)->evidence['retail']['annual_net'], 'Stored annual net preserves the below-cost edit');
$offerPorts->apply(Paymenter\Extensions\Servers\Domains\Catalogue\Edit::retainRenewal($binding,'com',$retained),$offerRevision());
$server = Server::findOrFail($tld->server_id);
$setting = $server->settings()->create(['key' => 'domains_due_days', 'value' => '14', 'type' => 'string']);
same('2027-10-06', $run()->details['renewal_due'], 'Configured due days applied');
$setting->update(['value' => '21']); $count = count($wire->calls); same('price_unconfirmed', $run()->code, 'Due days cannot equal default issue days'); same($count, count($wire->calls), 'Invalid shared calendar zero wire');
$setting->update(['value' => '0']); same('price_unconfirmed', $run()->code, 'Invalid cutoff cannot be guessed'); $setting->update(['value' => '7']);
// Build an independent synthetic live binding in this disposable fixture, never by runtime identity edits.
$live = new AccountBinding('enom', $tld->server_id, Environment::Live, 'account-enom');
DB::table('domains_accounts')->where('server_id', $tld->server_id)->update(['environment' => 'live', 'account_fingerprint' => $live->fingerprint]);
DB::table('domains_tlds')->where('id', $tld->id)->update(['account_fingerprint' => $live->fingerprint]);
DB::table('domains_prices')->where('server_id', $tld->server_id)->update(['account_fingerprint' => $live->fingerprint]);
$server->settings()->where('key', 'domains_catalogue_acceptance')->delete();
// Independently observed active facts/costs stay on their explicitly re-bound fixture rows.
$bindings->configure($tld->server_id, 'enom', Environment::Live, ['uid' => 'account-enom', 'token' => 'synthetic-secret']);
$liveTarget = new Target('example.com', $service->id, $owner->id, $tld->server_id, Environment::Live);
$count = count($wire->calls); same('account_unconfirmed', $reader->preview($liveTarget, $context)->code, 'Live read refuses unbound non-HTTPS site'); same($count, count($wire->calls), 'Wrong site no wire');
config(['app.url' => 'https://synthetic-production.invalid']); $bindings->confirmLiveSite($tld->server_id, 'https://synthetic-production.invalid');
$liveReview = $reader->preview($liveTarget, $context); same('ready', $liveReview->code, 'Synthetic live site/account proof eligible preview');
same(false, $liveReview->details['test_only'], 'Live badge derives from verified binding'); same(false, $liveReview->jsonSerialize()['can_confirm'], 'Even live preview never commits');
foreach (array_slice($wire->calls, $count) as $call) { same('live', $call[2], 'Synthetic transport receives verified live environment'); }
$count = count($wire->calls); config(['app.url' => 'https://other-site.invalid']);
same('account_unconfirmed', $reader->preview($liveTarget, $context)->code, 'Cross-site reuse refused'); same($count, count($wire->calls), 'Cross-site no wire');
};
nativeFixtureCases($cases,$fixture);
$nativeSuccessful=true;
