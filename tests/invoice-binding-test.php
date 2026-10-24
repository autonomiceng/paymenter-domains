<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/invoice-descriptions.php';
use App\Models\{Coupon, Invoice, InvoiceItem, InvoiceTransaction, Order, Service};
use Illuminate\Support\Facades\{DB, Event};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, InvoiceBinding, InvoiceFunding, PricingRule};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
extract(commerceFixture(true)); $clock = new FakeClock($now);
$admission = new CartAdmission($native, $sellers, new CheckoutEligibility($bindings, $clock), $clock); $admission->boot();
$binder = new InvoiceBinding($admission, $native, $prices, $clock); $binder->boot(); $binder->boot();
$user = commerceUser(); $cart = commerceCart($user); $product = $native->product($tlds[0], 'register');
$originalPlan = $product->plans()->where('billing_period', 3)->sole()->id;
commerceAdd($cart, $product, placeholderComFixtureName('three-placeholder.com'), 3); commerceAdd($cart, $product, placeholderComFixtureName('one-placeholder.com'));
$hosting = commerceHosting(); commerceAdd($cart, $hosting, 'hosting'); commerceCheckout($user, $cart);
same(1, Order::count(), 'Native order and all bindings commit together'); same(1, Invoice::count(), 'One shared native invoice');
same(2, Domain::count(), 'Two global holdings bound'); same(2, Operation::count(), 'Two synchronous operations');
$op = Operation::orderBy('id')->firstOrFail(); $domain = $op->domain; $service = $domain->service; $item = InvoiceItem::findOrFail($op->invoice_item_id);
same(3, (int) $op->term, 'Operation keeps purchased multi-year term'); same('23.40', $op->amount, 'Acquisition amount frozen');
same('23.40', $item->price, 'Native invoice keeps original checkout total');
same($originalPlan, $op->policy['checkout']['plan_id'], 'Original native plan frozen');
same(1, (int) $service->plan->billing_period, 'Same product annual service plan'); same($product->id, $service->product_id, 'Permanent product retained');
same('10.40', number_format((float) $service->price, 2, '.', ''), 'Annual renewal uses holder retail');
same(null, $service->expires_at, 'No native expiry before confirmed provider expiry'); same(null, $service->coupon_id, 'Checkout coupons do not reprice renewals');
same('three-placeholder.com', $service->label, 'Native service label is the name'); same('Register three-placeholder.com: 3 years (Oct 18, 2026 - Oct 18, 2029, from registration)', $item->description, 'Native invoice summary includes name and checkout term');
same('18.0000', $op->cost['estimated'], 'Estimated wholesale remains separate from final retail');
expect($op->payload['setup_required'] && count($op->payload['contacts']) === 4 && !array_key_exists('epp', $op->payload), 'Contact setup snapshot bound without EPP');
expect(!$service->properties()->where('key', 'domains_check')->exists(), 'Temporary receipt removed from service');
$raw = DB::table('domains_operations')->where('id', $op->id)->first(); expect(!str_contains($raw->cost, '18.0000') && !str_contains($raw->policy, 'three-placeholder.com'), 'Snapshots encrypted at rest');
expect(!array_key_exists('cost', $op->toArray()) && !array_key_exists('policy', $op->toArray()), 'Internal evidence hidden from serialization');
$before = DB::table('domains_operations')->where('id', $op->id)->first();
Event::dispatch(new App\Events\InvoiceItem\Created($item)); Event::dispatch(new App\Events\InvoiceItem\Created($item));
same(serialize($before), serialize(DB::table('domains_operations')->where('id', $op->id)->first()), 'Duplicate events are read-only');
same(2, Operation::count(), 'Duplicate event creates no operation');
DB::table('domains_domains')->where('id', $domain->id)->update(['provider_expires_at' => '2031-01-01']);
Event::dispatch(new App\Events\InvoiceItem\Created($item));
same('Register three-placeholder.com: 3 years (Oct 18, 2026 - Oct 18, 2029, from registration)', $item->fresh()->description, 'Provider expiry never rewrites registration dates');
DB::table('domains_domains')->where('id', $domain->id)->update(['provider_expires_at' => null]);
// Binding/checkout facts cannot be changed through ordinary model saves.
foreach (['term' => 1, 'amount' => '1.00', 'currency' => 'EUR', 'invoice_item_id' => 999, 'operation_key' => 'forged'] as $key => $value) {
    commerceReject(static fn () => $op->fresh()->fill([$key => $value])->save());
}
// Nested checkout and cost evidence is frozen, including removal of an existing key.
foreach (['policy' => ['checkout', 'price', 'seller_revision'], 'cost' => ['estimated', 'currency', 'unit']] as $field => $keys) {
    $saved = $op->fresh()->$field;
    foreach ($keys as $key) {
        foreach (['change', 'remove'] as $mutation) {
            $changed = $saved;
            if ($mutation === 'remove') { unset($changed[$key]); } else { $changed[$key] = 'changed'; }
            commerceReject(static fn () => $op->fresh()->fill([$field => $changed])->save());
            same($saved, $op->fresh()->$field, 'Refused nested operation evidence stays unchanged');
        }
    }
}
$snapshot = $domain->fresh()->snapshot;
foreach (['change', 'remove'] as $mutation) {
    $changed = $snapshot;
    if ($mutation === 'remove') { unset($changed['checkout']); } else { $changed['checkout'] = ['name' => reservedFixtureName('changed.example.com')]; }
    commerceReject(static fn () => $domain->fresh()->fill(['snapshot' => $changed])->save());
    same($snapshot, $domain->fresh()->snapshot, 'Refused domain checkout evidence stays unchanged');
}
$policy = $op->fresh()->policy; $policy['review_note'] = 'Additional non-frozen evidence';
expect($op->fresh()->update(['policy' => $policy]), 'A new non-frozen policy key can save');
same($policy, $op->fresh()->policy, 'New policy evidence persists alongside frozen checkout facts');
// A new holder after a seller change stays on its own account. Existing ownership never moves.
$sellers->change('com', 2, $tlds[1]->server_id, true, true, $now); $nsProduct = $native->product($tlds[1], 'register');
$cart = commerceCart($user); commerceAdd($cart, $nsProduct, placeholderComFixtureName('new-account-placeholder.com')); commerceAdd($cart, $nsProduct, placeholderComFixtureName('same-plan-placeholder.com')); commerceCheckout($user, $cart);
same(2, Order::count(), 'New selected account can bind new names');
same((int) $tlds[1]->server_id, (int) Domain::where('domain', 'new-account-placeholder.com')->sole()->server_id, 'Selected new account frozen');
same((int) $tlds[0]->server_id, (int) $domain->fresh()->server_id, 'Existing holding keeps original account');
foreach (['new-account-placeholder.com', 'same-plan-placeholder.com'] as $name) {
    $bound = Domain::where('domain', $name)->sole();
    same($name, $bound->service->properties()->where('key', 'domain')->sole()->value, 'Same-plan service property belongs to its exact reservation');
    same($name, $bound->operations()->sole()->policy['checkout']['name'], 'Same-plan operation freezes its own name');
}
$other = commerceUser(); $cart = commerceCart($other); commerceAdd($cart, $nsProduct, placeholderComFixtureName('three-placeholder.com')); commerceAdd($cart, $hosting, 'hosting');
$counts = [Order::count(), Invoice::count(), Service::count(), Domain::count(), Operation::count()]; $stock = $hosting->fresh()->stock;
commerceCheckout($other, $cart); same($counts, [Order::count(), Invoice::count(), Service::count(), Domain::count(), Operation::count()], 'Global reservation rolls back cross-account checkout'); same($stock, $hosting->fresh()->stock, 'Conflict rolls back hosting stock');
// Failure after the first line already bound must roll back EVERY native and ledger row.
$fail = false; Event::listen(App\Events\InvoiceItem\Created::class, static function ($e) use (&$fail): void {
    if ($fail && str_contains($e->invoiceItem->description, 'fail-second-placeholder.com')) { throw new DomainException('Synthetic second-line refusal'); }
});
$cart = commerceCart($user); commerceAdd($cart, $nsProduct, placeholderComFixtureName('rolled-first-placeholder.com')); commerceAdd($cart, $nsProduct, placeholderComFixtureName('fail-second-placeholder.com')); commerceAdd($cart, $hosting, 'hosting');
$fail = true; commerceCheckout($user, $cart); $fail = false;
same($counts, [Order::count(), Invoice::count(), Service::count(), Domain::count(), Operation::count()], 'Late binding exception rolls back entire multi-line checkout');
expect(!Domain::where('domain', 'rolled-first-placeholder.com')->exists(), 'First reservation rolls back with second failure');
// Discounted acquisition freezes its positive net; holder renewal remains independently priced.
$cart = commerceCart($user); commerceAdd($cart, $nsProduct, placeholderComFixtureName('coupon-binding-placeholder.com'));
$coupon = Coupon::create(['code' => 'BINDHALF', 'type' => 'percentage', 'value' => 50, 'applies_to' => 'price', 'recurring' => 0, 'max_uses_per_user' => 1, 'max_uses' => 2]);
$cart->update(['coupon_id' => $coupon->id]); commerceCheckout($user, $cart);
$discounted = Domain::where('domain', 'coupon-binding-placeholder.com')->sole();
same('3.90', $discounted->operations()->sole()->amount, 'Positive below-cost coupon amount frozen once');
same('10.40', number_format((float) $discounted->service->price, 2, '.', ''), 'Lifetime acquisition coupon never changes annual holder retail');
same($coupon->id, $discounted->service->coupon_id, 'Coupon association retained for native usage accounting');
same(1, $coupon->services()->count(), 'Domain acquisition consumes a native coupon use');
try { App\Classes\Cart::validateCoupon($coupon->code); expect(false, 'Per-user limited coupon must refuse reuse'); }
catch (App\Exceptions\DisplayException $e) { expect(str_contains($e->getMessage(), 'maximum number'), 'Native per-user coupon limit explains refusal'); }
same('Register one-placeholder.com: 1 year (Oct 18, 2026 - Oct 18, 2027, from registration)', InvoiceItem::findOrFail(Domain::where('domain', 'one-placeholder.com')->sole()->operations()->sole()->invoice_item_id)->description, 'Singular invoice wording');
// Funding locks the complete native invoice prefix BEFORE domain then operation rows.
$funded = static function () use ($op): array {
    return DB::transaction(static function () use ($op): array {
        $funding = InvoiceFunding::lock($op->invoice_id);
        $domain = Domain::whereKey($op->domain_id)->lockForUpdate()->firstOrFail();
        $locked = Operation::whereKey($op->id)->lockForUpdate()->firstOrFail();
        return $funding->requirePaid($locked, $domain);
    });
};
$invoiceId = $op->invoice_id;
$payment = static fn ($amount, $status = 'succeeded') => InvoiceTransaction::create(['invoice_id' => $invoiceId, 'amount' => $amount, 'fee' => '0.00', 'status' => $status]);
commerceReject($funded); DB::table('invoices')->where('id', $invoiceId)->update(['status' => 'paid']); commerceReject($funded);
$payment('23.40'); commerceReject($funded); // this domain line covered, mixed invoice not yet covered
$payment('12.80', 'processing'); $payment('12.80', 'failed'); commerceReject($funded);
$payment('12.80'); $evidence = $funded(); same(3620, $evidence['invoice_total_minor'], 'Funding includes both domains AND hosting');
same(3620, $evidence['succeeded_net_minor'], 'Only succeeded transactions contribute');
$payment('-0.01'); commerceReject($funded); $payment('0.01'); same(3620, $funded()['succeeded_net_minor'], 'Signed successful adjustments net correctly');
DB::table('invoices')->where('id', $invoiceId)->update(['status' => 'pending']); commerceReject($funded);
DB::table('invoices')->where('id', $invoiceId)->update(['status' => 'paid']);
DB::table('invoice_items')->where('id', $item->id)->update(['reference_id' => $service->id + 999]); commerceReject($funded);
commerceReject(static fn () => Event::dispatch(new App\Events\InvoiceItem\Created($item)));
DB::table('invoice_items')->where('id', $item->id)->update(['reference_id' => $service->id]);
DB::table('services')->where('id', $service->id)->update(['user_id' => $other->id]); commerceReject($funded);
DB::table('services')->where('id', $service->id)->update(['user_id' => $user->id]);
DB::table('products')->where('id', $product->id)->update(['server_id' => $tlds[1]->server_id]); commerceReject($funded);
DB::table('products')->where('id', $product->id)->update(['server_id' => $tlds[0]->server_id]);
same(3620, $funded()['succeeded_net_minor'], 'Restored exact binding yields funding evidence only');
// Read-only recovery snapshots cannot alter the live funding context's captured native rows.
DB::table('invoices')->where('id',$invoiceId)->update(['status'=>'pending']);
DB::transaction(static function()use($op):void{
    $context=InvoiceFunding::lock($op->invoice_id);$domain=$op->fresh()->domain;
    $facts=$context->initialFacts($domain);$facts['invoices']->first()->status='paid';
    commerceReject(static fn()=>$context->requirePaid($op->fresh(),$domain));
});
DB::table('invoices')->where('id',$invoiceId)->update(['status'=>'paid']);
$payment('-0.01');
DB::transaction(static function()use($op):void{
    $context=InvoiceFunding::lock($op->invoice_id);$domain=$op->fresh()->domain;
    $facts=$context->initialFacts($domain);$facts['transactions']->last()->amount='0.00';
    commerceReject(static fn()=>$context->requirePaid($op->fresh(),$domain));
});
$payment('0.01');
DB::transaction(static function()use($op):void{
    $context=InvoiceFunding::lock($op->invoice_id);$domain=$op->fresh()->domain;$facts=$context->initialFacts($domain);
    $facts['items']->first()->price='9999.00';$facts['orders']->first()->user_id=0;
    $facts['service']->user_id=0;$facts['product']->server_id=0;$facts['plan']->billing_period=99;
    same(3620,$context->requirePaid($op->fresh(),$domain)['succeeded_net_minor'],'Detached rows preserve the live context');
});
$expired = DB::transaction(static function () use ($invoiceId) {
    $context = InvoiceFunding::lock($invoiceId); commerceReject(static fn () => clone $context); return $context;
});
$rolledBack = null;
try {
    DB::transaction(static function () use ($invoiceId, &$rolledBack): void {
        $rolledBack = InvoiceFunding::lock($invoiceId); commerceReject(static fn () => clone $rolledBack);
        throw new DomainException('Synthetic rollback');
    });
} catch (DomainException) {}
$payment('-0.01');
DB::transaction(static fn () => commerceReject(static fn () => $rolledBack->requirePaid($op->fresh(), $domain->fresh())));
DB::transaction(static fn () => commerceReject(static fn () => $expired->requirePaid($op->fresh(), $domain->fresh())));
commerceReject(static fn () => InvoiceFunding::lock($invoiceId));

same('awaiting_payment', $op->fresh()->state, 'Funding evidence does not claim or dispatch operation');
same(0, DB::transactionLevel(), 'No checkout or funding transaction leaked');
$nativeSuccessful = true; echo "Synchronous invoice binding, global account reservations, annual transitions and full-invoice net funding passed.\n";
