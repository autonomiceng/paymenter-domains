<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adopted-renewal.php';
use Illuminate\Support\Facades\{Auth, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{HoldingAnchor, HoldingLocks, InvoiceBinding};
extract(adoptedRenewalFixture());
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
$digest = \Paymenter\Extensions\Servers\Domains\Services\AdoptionMapping::stamp($intent);
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
try { same('Unavailable; staff review required', DomainFacts::for($domain->fresh())['Adoption evidence'], 'Unreadable proof has explicit staff review state'); }
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
echo "Adoption link, live environment, identity, revision, expiry, encryption, staff review and acquisition refusals passed.\n";
$nativeSuccessful = true;
