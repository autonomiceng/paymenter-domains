<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native checkout/contact/tax events and persisted invoice snapshots cannot be proved by a unit fixture.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{Invoice, Order};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Query};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers;
expect(is_file(dirname(__DIR__).'/Domains/Services/AcquisitionOffers.php'), 'Public and native checkout need a shared offer batch adapter');
require __DIR__.'/Support/offer-commerce.php';
$mutate = null;
Illuminate\Support\Facades\Event::listen(App\Events\Order\Creating::class, static function () use (&$mutate): void { if ($mutate) { $mutate(); } });
$fixture = offerCommerceFixture(); extract($fixture);
$public = $storefront->offer('com', 'register', 3);
$staff = $ports->readOffers(new Query($binding, ['com'], 'register', 3), Context::Staff)[0];
same(['$23.40', '$10.40', $binding->serverId, 3, '23.40'], [$public['price'], $public['renew'], $public['selection']->serverId,
    $public['selection']->term, $staff->retail], 'Public offer and Staff reader use the selected account and exact term total');
$user = commerceUser(); $cart = commerceCart($user);
$line = commerceAdd($cart, $public['product'], 'catalogue-placeholder.com', 3);
$token = $line->checkout_config['domains_check']; $before = $reads->calls;
$admission->refresh(); same($before, $reads->calls, 'Current add receipt survives refresh without another provider read');
commerceCheckout($user, $cart);
same([1, 1, 1], [Order::count(), Invoice::count(), Operation::count()], 'Real native checkout creates one atomic obligation');
$op = Operation::firstOrFail();
same([$binding->serverId, 3, '23.40', '10.40'], [(int)$op->server_id, (int)$op->term, $op->policy['price']['retail'],
    $op->domain->renewal_policy['retail']], 'Acquisition and annual renewal freeze the same admitted batch');
same($before, $reads->calls, 'Locked checkout and invoice binding never resolve a provider');
// Mutations happen after the last unlocked refresh and native locks, before our batch admission.
[$secondBinding, $second] = offerCommerceSecond($fixture);
foreach (['seller', 'defaults', 'facts', 'publication', 'credential', 'generation', 'second_account'] as $change) {
    $cart = commerceCart($user); commerceAdd($cart, $public['product'], 'race-'.str_replace('_', '-', $change).'-placeholder.com', 3);
    commerceAdd($cart, $second['product'], 'race-'.str_replace('_', '-', $change).'-placeholder.net');
    $counts = [Order::count(), Invoice::count(), Operation::count()]; $before = $reads->calls;
    $reached = false; $saved = [];
    $mutate = static function () use ($change, $binding, $secondBinding, &$saved, &$reached): void {
        $reached = true;
        if ($change === 'generation') {
            $row = new App\Models\Setting(['key' => Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate::KEY,
                'settingable_type' => null, 'settingable_id' => null, 'type' => 'string', 'encrypted' => true]);
            $row->value = json_encode(['state' => 'legacy', 'generation' => 2]); $row->save(); return;
        }
        if ($change === 'credential') {
            $row = App\Models\Server::find($binding->serverId)->settings()->where('key', 'domains_verified_credentials')->sole();
            $saved[] = ['settings', $row->id, $row->getRawOriginal()]; DB::table('settings')->where('id', $row->id)->update(['value' => 'revoked']); return;
        }
        $table = $change === 'seller' ? 'domains_sales_policies' : ($change === 'defaults' ? 'domains_accounts' : 'domains_tlds');
        $row = DB::table($table)->where('server_id', $change === 'second_account' ? $secondBinding->serverId : $binding->serverId)->first();
        $saved[] = [$table, $row->id, (array)$row];
        $update = match ($change) {
            'seller' => ['revision' => $row->revision + 1, 'server_id' => $secondBinding->serverId],
            'defaults' => ['catalogue_defaults' => json_encode(['_revision' => 99])],
            'publication' => ['catalogue_published_revision' => null],
            default => ['catalogue_facts' => null],
        };
        DB::table($table)->where('id', $row->id)->update($update);
    };
    $attempt = commerceCheckout($user, $cart); $mutate = null;
    $failure = collect($attempt->effects['dispatches'] ?? [])->firstWhere('name', 'notify')['params'][0]['message'] ?? '';
    expect($reached, $change.': native admission boundary was reached: '.$failure);
    same($counts, [Order::count(), Invoice::count(), Operation::count()], $change.': entire mixed-account checkout rolls back before obligations');
    same($before, $reads->calls, $change.': no provider read inside native locks');
    // Native outer rollback restores the synthetic mutation too; no simulated concurrent-DB claim.
}
// Both accounts can also succeed in one native batch with ordinary hosting and discounts.
$cart = commerceCart($user); commerceAdd($cart, $public['product'], 'mixed-acquire-placeholder.com', 3);
commerceAdd($cart, $second['product'], 'mixed-acquire-placeholder.net');
$hosting = commerceHosting(); commerceAdd($cart, $hosting, 'hosting');
$coupon = App\Models\Coupon::create(['code' => 'OFFER-HALF', 'type' => 'percentage', 'value' => 50, 'applies_to' => 'price']);
$cart->update(['coupon_id' => $coupon->id]); $before = $reads->calls; $count = Operation::count();
commerceCheckout($user, $cart);
same($count + 2, Operation::count(), 'Two accounts share one successful native order');
same(9, $hosting->fresh()->stock, 'Native hosting stock is decremented once');
same($before, $reads->calls, 'Mixed native checkout uses only the unlocked receipts');
same('11.70', Operation::where('server_id', $binding->serverId)->latest('id')->first()->amount, 'Positive below-cost coupon remains a native retail decision');
// The unchanged native validation body also protects the dormant catalogue path.
foreach (['quantity', 'options', 'duplicate', 'owner', 'setup', 'cached_price'] as $bad) {
    $cart = commerceCart($user); $line = commerceAdd($cart, $public['product'], 'invalid-'.str_replace('_', '-', $bad).'-placeholder.com', 3);
    $counts = [Order::count(), Invoice::count(), Operation::count()]; $nativePrice = $line->plan->prices()->where('currency_code', 'USD')->sole();
    if ($bad === 'quantity') { DB::table('cart_items')->where('id', $line->id)->update(['quantity' => 2]); }
    if ($bad === 'options') { DB::table('cart_items')->where('id', $line->id)->update(['config_options' => json_encode([['option_id' => 1, 'option_name' => 'Injected', 'value_name' => 'Invalid']])]); }
    if ($bad === 'duplicate') { $copy = $line->replicate(); $copy->saveQuietly(); }
    if ($bad === 'setup') { DB::table('prices')->where('id', $nativePrice->id)->update(['setup_fee' => '1.00']); }
    if ($bad === 'cached_price') { $mutate = static fn () => App\Classes\Cart::get()->items->first()->plan->prices->first()->price = '1.00'; }
    commerceCheckout($bad === 'owner' ? commerceUser() : $user, $cart); $mutate = null;
    same($counts, [Order::count(), Invoice::count(), Operation::count()], $bad.': native validation refuses without partial obligations');
    if ($bad === 'setup') { DB::table('prices')->where('id', $nativePrice->id)->update(['setup_fee' => '0.00']); }
}
// Use a real transfer product/receipt as well; public lookup remains synthetic and explicitly unverified.
$transfer = $storefront->offer('com', 'transfer', 1);
$cart = commerceCart($user); commerceAdd($cart, $transfer['product'], 'transfer-snapshot-placeholder.com');
$count = Operation::count(); commerceCheckout($user, $cart);
same($count + 1, Operation::count(), 'Transfer retains the existing native eligibility/contact/invoice fixture');
same(['transfer', '9.10', '10.40'], [Operation::latest('id')->first()->action,
    Operation::latest('id')->first()->policy['price']['retail'], Operation::latest('id')->first()->domain->renewal_policy['retail']], 'Transfer freezes its acquisition and same-account annual renewal');
// Expired receipts refresh outside locks; changed total must be shown before another checkout click.
$cart = commerceCart($user); $line = commerceAdd($cart, $public['product'], 'refresh-total-placeholder.com', 3);
Illuminate\Support\Once::flush();
$page = Livewire\Livewire::actingAs($user)->withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('use_credits', false);
expect($cart->items()->count() === 1, 'Refresh fixture retains one real cart item');
expect($page->get('total') !== null, 'Mounted refresh fixture has a visible total');
$debug = []; Livewire\Livewire::listen('call', static function ($component, $method) use (&$debug): void { if ($method === 'checkout') { $c = App\Classes\Cart::getOnce(); $debug[] = [request()->cookie('cart'), $c->id, $c->items->count()]; } });
$clock->instant = $now->modify('+6 minutes'); $before = $reads->calls;
$q = new Query($binding, ['com'], 'register', 3);
$ports->apply(new Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($binding, 'com', [
    new Paymenter\Extensions\Servers\Domains\Catalogue\Cost('register', 3, true, '21.0000',
        Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal, 'synthetic', $clock->now(), $clock->now()->modify('+1 day'))
]), $ports->readOffers($q, Context::Staff)[0]->revision);
$counts = [Order::count(), Invoice::count(), Operation::count()];
app('request')->cookies->set('cart', $cart->ulid);
Illuminate\Support\Once::flush(); $page->call('checkout', ['contact' => commerceOrderContact($user), 'save_account' => false]);
same($counts, [Order::count(), Invoice::count(), Operation::count()], 'Changed total stops the first checkout click');
$notice = collect($page->effects['dispatches'] ?? [])->firstWhere('name', 'notify')['params'][0]['message'] ?? '';
expect(str_contains($notice, 'Review the total'), 'Changed-total notice: '.$notice.' '.json_encode([$cart->id, $cart->ulid, $debug]));
same($before + 1, $reads->calls, 'Stale five-minute receipt costs one unlocked read');
app('request')->cookies->set('cart', $cart->ulid);
Illuminate\Support\Once::flush(); $page->call('checkout', ['contact' => commerceOrderContact($user), 'save_account' => false]);
same($counts[2] + 1, Operation::count(), 'Second click accepts the visible new total');
same('27.30', Operation::latest('id')->first()->policy['price']['retail'], 'Only the new obligation freezes the new total');
same('23.40', $op->fresh()->policy['price']['retail'], 'Existing invoice evidence never changes');
commerceReject(static function () use ($op): void { $p = $op->fresh()->policy; $p['checkout']['catalogue']['catalogue']['generation']++; $op->fresh()->update(['policy' => $p]); });
$nativeSuccessful = true;
echo "Offer commerce: ok\n";
