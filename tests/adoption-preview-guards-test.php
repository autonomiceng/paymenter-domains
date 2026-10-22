<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/adoption-preview.php';
use App\Models\{Invoice, Server, Service, ServiceCancellation};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};
use Paymenter\Extensions\Servers\Domains\Data\Adoption\{StaffContext, Target};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Adoption};
use Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances;
extract(adoptionFixture());
$run = fn () => $reader->preview($target, $context);
foreach ([['quantity', 2], ['currency_code', 'EUR'], ['plan_id', $product->plans()->where('billing_period', 3)->sole()->id]] as [$key, $value]) {
    $old = $service->fresh()->getAttribute($key); DB::table('services')->where('id', $service->id)->update([$key => $value]);
    $before = count($wire->calls); same('mapping_unconfirmed', $run()->code, 'Non-dedicated annual mapping refused: '.$key);
    same($before, count($wire->calls), 'Invalid mapping no wire'); DB::table('services')->where('id', $service->id)->update([$key => $old]);
}
DB::table('services')->where('id', $service->id)->update(['expires_at' => '2026-11-01']);
$count = count($wire->calls); same('native_expiry_present', $run()->code, 'Native cron expiry must be qualified before preview'); same($count, count($wire->calls), 'Native expiry zero wire');
DB::table('services')->where('id', $service->id)->update(['expires_at' => null]);
$transfer = $native->product($tld, 'transfer'); $transferPlan = $native->annualPlan($transfer);
DB::table('services')->where('id', $service->id)->update(['product_id' => $transfer->id, 'plan_id' => $transferPlan->id]);
$count = count($wire->calls); same('mapping_unconfirmed', $run()->code, 'Transfer route needs separate normalization'); same($count, count($wire->calls), 'Transfer route zero wire');
DB::table('services')->where('id', $service->id)->update(['product_id' => $product->id, 'plan_id' => $plan->id]);
$hostingProduct = $product->replicate(); $hostingProduct->slug = 'synthetic-hosting'; $hostingProduct->server_id = null; $hostingProduct->save();
$hosting = Service::create(['user_id' => $owner->id, 'order_id' => $order->id, 'product_id' => $hostingProduct->id,
    'plan_id' => $plan->id, 'quantity' => 1, 'price' => '10.00', 'currency_code' => 'USD', 'status' => 'active']);
$hosting->properties()->create(['key' => 'domain', 'value' => 'example.com']);
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
same('competing_holding', $run()->code, 'Unmapped server-backed product requires explicit hosting classification');
DB::transaction(fn () => \Paymenter\Extensions\Servers\Domains\Models\WatchProductMap::create(['product_id' => $hostingProduct->id, 'property_key' => 'domain',
    'enabled' => false, 'revision' => 1, 'edited_by' => $staff->id, 'reason' => 'Reviewed synthetic hosting source']));
same('ready', $run()->code, 'Reviewed hosting map allows explicit server-backed coexistence');
DB::table('products')->where('id', $hostingProduct->id)->update(['server_id' => $legacyServer->id]);
same('competing_holding', $run()->code, 'A hosting map cannot exempt a known registrar extension');
DB::table('products')->where('id', $hostingProduct->id)->update(['server_id' => null]);
$peer = Service::create(['user_id' => $other->id, 'order_id' => $order->id, 'product_id' => $product->id,
    'plan_id' => $plan->id, 'quantity' => 1, 'price' => '10.00', 'currency_code' => 'USD', 'status' => 'pending']);
$peerProperty = $peer->properties()->create(['key' => 'domain', 'value' => 'EXAMPLE.COM']);
same('competing_holding', $run()->code, 'Other service canonical name collision refused');
$peerProperty->update(['value' => 'other-placeholder.com']);
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
$eligibility = $rate->eligibility;
DB::table('domains_prices')->where('id', $rate->id)->update(['eligibility' => json_encode([...$eligibility, 'staff_alert' => 'below_cost'])]);
same('below_cost', $run()->details['price_notice'], 'Below-cost retail is reviewed without a floor');
DB::table('domains_prices')->where('id', $rate->id)->update(['eligibility' => json_encode($eligibility)]);
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
$server->settings()->where('key', CatalogueAcceptances::KEY)->delete();
CatalogueAcceptances::save($live, 0, ['com' => ['register' => [1, 3], 'renew' => [1], 'transfer' => [1], 'registry' => true, 'renewal_safe' => true]]);
$bindings->configure($tld->server_id, 'enom', Environment::Live, ['uid' => 'account-enom', 'token' => 'synthetic-secret']);
$liveTarget = new Target('example.com', $service->id, $owner->id, $tld->server_id, Environment::Live);
$count = count($wire->calls); same('account_unconfirmed', $reader->preview($liveTarget, $context)->code, 'Live read refuses unbound non-HTTPS site'); same($count, count($wire->calls), 'Wrong site no wire');
config(['app.url' => 'https://synthetic-production.invalid']); $bindings->confirmLiveSite($tld->server_id, 'https://synthetic-production.invalid');
$liveReview = $reader->preview($liveTarget, $context); same('ready', $liveReview->code, 'Synthetic live site/account proof eligible preview');
same(false, $liveReview->details['test_only'], 'Live badge derives from verified binding'); same(false, $liveReview->jsonSerialize()['can_confirm'], 'Even live preview never commits');
foreach (array_slice($wire->calls, $count) as $call) { same('live', $call[2], 'Synthetic transport receives verified live environment'); }
$count = count($wire->calls); config(['app.url' => 'https://other-site.invalid']);
same('account_unconfirmed', $reader->preview($liveTarget, $context)->code, 'Cross-site reuse refused'); same($count, count($wire->calls), 'Cross-site no wire');
$nativeSuccessful = true;
echo "Adoption native route, invoice history, calendar and synthetic live site guards passed\n";
