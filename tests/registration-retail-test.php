<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native search, Livewire cart, invoice events and frozen obligations need the real SQLite fixture.
// All registrar replies are synthetic; no provider transport or purchase is dispatched.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{InvoiceItem, Order};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, Edit, Facts, FactsObserved, FeedObserved, Intent, OfferState, Query, StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AcquisitionOffers, InvoiceBinding, PricingRule};

extract(commerceFixture(true, ending: 'net', registrars: ['enom']));
$binding = AccountBindings::binding(Account::where('server_id', $tlds[0]->server_id)->sole());
$query = new Query($binding, ['net'], 'register');
$revision = fn () => $ports->readOffers($query, Context::Staff)[0]->revision;
$clock->instant = $now->modify('+1 minute');
$costs = [new Cost('renew', 1, true, '10.9900', CostUnit::TermTotal, 'synthetic', $clock->now(), $now->modify('+1 day'))];
foreach ([1, 2, 5, 10] as $years) {
    // The longer Enom registration bucket deliberately differs from both annual inputs.
    $costs[] = new Cost('register', $years, true, $years === 1 ? '17.9900' : PricingRule::decimal(100000 * $years, 4),
        CostUnit::TermTotal, 'synthetic', $clock->now(), $now->modify('+1 day'));
}
$ports->apply(new FeedObserved($binding, 'net', $costs), $revision());
$ports->apply(new FactsObserved($binding, 'net', new Facts(['register' => [1, 2, 5, 10], 'renew' => [1], 'transfer' => [1]],
    [], true, false, false, 'synthetic', $clock->now(), $now->modify('+1 day'))), $revision());
$eligibility = activeFixtureEligibility($bindings, $clock);
$admission = activeFixtureAdmission($native, $sellers, $eligibility, $clock); $admission->boot();
$binder = new InvoiceBinding($admission, $native, $prices, $clock); $binder->boot(); app()->instance(InvoiceBinding::class, $binder);
$store = activeFixtureStorefront($sellers, $prices, $native, $bindings, $eligibility, $clock);
$expected = [1 => '17.99', 2 => '28.98', 5 => '61.95', 10 => '116.90'];
$issued = [];
foreach (['markup_percent', 'fixed'] as $mode) {
    $label = str_replace('_', '-', $mode);
    $rule = [...PricingRule::DEFAULT, 'mode' => $mode, 'markup' => '0.00',
        'fixed' => ['register' => '17.99', 'renew' => '10.99', 'transfer' => '12.00']];
    $ports->apply(Edit::override($binding, 'net', new Intent(sell: true, longest: 10, transfers: true), $rule), $revision());
    Cache::flush();
    $search = $store->check('search-'.$label.'.net');
    same('available', $search['status'], $mode.': searchable');
    same(array_map(fn ($v) => '$'.$v, $expected), $search['terms'], $mode.': search includes the first registration year');
    $user = commerceUser(); $cart = commerceCart($user);
    foreach ($expected as $years => $total) {
        $offer = $offers->offer('net', 'register', $years);
        $item = commerceAdd($cart, $offer['product'], $label.'-'.$years.'.net', $years);
        same($total, number_format((float) $item->price->total, 2, '.', ''), $mode.': cart total '.$years);
    }
    $page = commerceCheckout($user, $cart); $page->assertHasNoErrors();
    same(count($issued) + 1, Order::count(), $mode.': one native order');
    foreach ($expected as $years => $total) {
        $op = Operation::whereHas('domain', fn ($q) => $q->where('domain', $label.'-'.$years.'.net'))->sole();
        $item = InvoiceItem::findOrFail($op->invoice_item_id);
        same([$years, $total, $total, $total], [(int) $op->term, $op->amount, $item->price, $op->policy['price']['retail']], $mode.': invoice and registrar order term '.$years);
        same($years === 1 ? '17.9900' : PricingRule::decimal(100000 * $years, 4), $op->cost['estimated'], $mode.': selected-term wholesale retained');
        same('10.99', number_format((float) $op->domain->service->price, 2, '.', ''), $mode.': future annual renewal');
    }
    $issued[] = DB::table('invoice_items')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}
// A later pricing save must not change either issued invoice or its frozen operation amount.
$ports->apply(Edit::override($binding, 'net', new Intent(sell: true, longest: 10, transfers: true),
    [...PricingRule::DEFAULT, 'mode' => 'fixed', 'fixed' => ['register' => '19.99', 'renew' => '12.99', 'transfer' => '12.00']]), $revision());
same($issued[1], DB::table('invoice_items')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), 'Issued invoices never reprice');
same('28.98', Operation::whereHas('domain', fn ($q) => $q->where('domain', 'fixed-2.net'))->sole()->amount, 'Issued operation amount remains frozen');
// The mixed retail must still cover the independently quoted selected term's wholesale cost.
$clock->instant = $now->modify('+2 minutes');
$ports->apply(new FeedObserved($binding, 'net', [new Cost('register', 10, true, '200.0000', CostUnit::TermTotal,
    'synthetic', $clock->now(), $now->modify('+1 day'))]), $revision());
same('below_cost', $ports->readOffers(new Query($binding, ['net'], 'register', 10), Context::Staff)[0]->reason, 'Selected-term below-cost guard remains authoritative');
$nativeSuccessful = true;
echo "Registration retail search/cart/invoice and exact wholesale checks passed.\n";
