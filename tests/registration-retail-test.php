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
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, Edit, Facts, FactsObserved, FeedObserved, Intent, OfferState, Query, Retail};
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
    if ($mode === 'markup_percent') {
        // Replay a fresh old-rule publication: upgrading must use the saved costs, without a feed read.
        $oldTotals = [1 => '17.99', 2 => '21.98', 5 => '54.95', 10 => '109.90'];
        $product = $native->product($tlds[0], 'register');
        foreach ($oldTotals as $years => $total) {
            DB::table('domains_prices')->where('server_id', $binding->serverId)->where('tld', 'net')
                ->where('action', 'register')->where('term', $years)->update(['retail' => $total]);
            $product->plans()->where('billing_period', $years)->sole()->prices()->where('currency_code', 'USD')->update(['price' => $total]);
        }
        $nativeTotals = static function () use ($product, $expected): array {
            $totals = [];
            foreach ($expected as $years => $total) {
                $plan = $product->plans()->where('billing_period', $years)->sole();
                $totals[$years] = number_format((float) $plan->price('USD')->total, 2, '.', '');
            }
            return $totals;
        };
        same($oldTotals, $nativeTotals(), 'Upgrade starts with actual old native plan amounts');
        $nativeIds = $product->plans()->orderBy('id')->pluck('id')->all();
        $priceIds = DB::table('prices')->whereIn('plan_id', $nativeIds)->orderBy('id')->pluck('id')->all();
        $published = OfferState::decode($tlds[0]->fresh()->catalogue_published_revision);
        unset($published['retailRule']); // Legacy publications have only offer/defaults/selection.
        DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_published_revision' => OfferState::json($published)]);
        $evidence = DB::table('domains_prices')->orderBy('id')->pluck('catalogue_observation', 'id')->all();
        $readsBefore = $reads->calls;
        // Installation may run while deployment sends are off. Publishing retail must still project
        // retained plans; sale admission remains independently closed until acceptance returns.
        $deployment = config('domains');
        config(['domains.enom_identity.environment' => 'test', 'domains.enom_catalogue.enabled' => true,
            'domains.enom_writes.registration' => false, 'domains.enom_writes.renewal' => false]);
        $renewal = \Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::class;
        App\Models\Server::findOrFail($binding->serverId)->settings()->updateOrCreate(['key' => $renewal::KEY],
            ['type' => 'string', 'encrypted' => true, 'value' => json_encode(['fingerprint' => $binding->fingerprint, ...$renewal::protocol($binding)])]);
        (new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
        // Check before synthetic fixture ports or a later edit can repair the installed publication.
        Illuminate\Support\Once::flush();
        same($expected, $nativeTotals(), 'Upgrade republishes the native amounts the storefront compares: '.json_encode($nativeTotals()));
        foreach ($expected as $years => $total) {
            same($total, number_format((float) DB::table('domains_prices')->where('server_id', $binding->serverId)
                ->where('tld', 'net')->where('action', 'register')->where('term', $years)->sole()->retail, 2, '.', ''), 'Upgrade publishes matching Domains retail '.$years);
        }
        same($nativeIds, $product->plans()->orderBy('id')->pluck('id')->all(), 'Upgrade preserves native plan identities');
        same($priceIds, DB::table('prices')->whereIn('plan_id', $nativeIds)->orderBy('id')->pluck('id')->all(), 'Upgrade preserves native price identities');
        same($evidence, DB::table('domains_prices')->orderBy('id')->pluck('catalogue_observation', 'id')->all(), 'Upgrade preserves saved cost evidence and its age');
        same($readsBefore, $reads->calls, 'Upgrade republication never resolves a provider');
        $priceRows = DB::table('domains_prices')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $nativeRows = DB::table('prices')->orderBy('id')->get()->toJson();
        (new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
        same($priceRows, DB::table('domains_prices')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), 'Already-current installation does not republish');
        same($nativeRows, DB::table('prices')->orderBy('id')->get()->toJson(), 'Already-current installation leaves native prices untouched');
        $installedOffers = \Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::resolve(AcquisitionOffers::class);
        commerceReject(fn () => $installedOffers->offer('net', 'register', 2));
        // A native failure must roll back Domains rows and the publication marker too, allowing retry.
        DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_published_revision' => OfferState::json($published)]);
        $twoYearPrice = $product->plans()->where('billing_period', 2)->sole()->prices()->where('currency_code', 'USD')->sole();
        DB::table('prices')->where('id', $twoYearPrice->id)->update(['price' => $oldTotals[2]]);
        $publication = static fn () => [DB::table('domains_prices')->orderBy('id')->get()->toJson(),
            DB::table('domains_tlds')->orderBy('id')->get()->toJson(), DB::table('prices')->orderBy('id')->get()->toJson()];
        $beforeFailure = $publication();
        DB::statement('CREATE TRIGGER refuse_retail_projection BEFORE UPDATE ON prices WHEN OLD.id = '.$twoYearPrice->id." BEGIN SELECT RAISE(ABORT, 'synthetic retail projection refusal'); END");
        try { (new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install(); expect(false, 'Upgrade must reach the native projection'); }
        catch (Illuminate\Database\QueryException $error) { expect(str_contains($error->getMessage(), 'synthetic retail projection refusal'), 'Expected native projection fault'); }
        DB::statement('DROP TRIGGER refuse_retail_projection');
        same($beforeFailure, $publication(), 'Native projection failure rolls back Domains publication and native prices together');
        (new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
        Illuminate\Support\Once::flush();
        same($expected, $nativeTotals(), 'Upgrade retry projects every native amount');
        config(['domains.enom_writes.registration' => true, 'domains.enom_writes.renewal' => true]);
        $installedStore = new \Paymenter\Extensions\Servers\Domains\Services\Storefront($sellers, $prices, $native, $bindings, $eligibility, $clock);
        Cache::flush();
        $installedSearch = $installedStore->check('installed-upgrade.net');
        same('available', $installedSearch['status'], 'Installed search reopens through normal admission');
        same(array_map(fn ($v) => '$'.$v, $expected), $installedSearch['terms'], 'Installed search offers all upgraded terms');
        $upgradeUser = commerceUser(); $upgradeCart = commerceCart($upgradeUser);
        $upgradeItem = commerceAdd($upgradeCart, $installedOffers->offer('net', 'register', 2)['product'], 'upgrade-cart.net', 2);
        $upgradePage = Livewire\Livewire::actingAs($upgradeUser)->withCookies(['cart' => $upgradeCart->ulid])
            ->test(App\Livewire\Cart::class)->set('use_credits', false);
        same('28.98', number_format((float) $upgradeItem->fresh()->price->total, 2, '.', ''), 'Upgraded two-year cart line');
        same('28.98', number_format((float) $upgradePage->get('total')->total, 2, '.', ''), 'Upgraded two-year native cart total matches its line');
        config(['domains' => $deployment]);
    }
    Cache::flush();
    $search = $store->check('search-'.$label.'.net');
    same('available', $search['status'], $mode.': searchable');
    same(array_map(fn ($v) => '$'.$v, $expected), $search['terms'], $mode.': search includes the first registration year: '.json_encode($search['terms']));
    $user = commerceUser(); $cart = commerceCart($user);
    foreach ($expected as $years => $total) {
        $offer = $offers->offer('net', 'register', $years);
        $item = commerceAdd($cart, $offer['product'], $label.'-'.$years.'.net', $years);
        same($total, number_format((float) $item->price->total, 2, '.', ''), $mode.': cart total '.$years);
        // Landing's breakdown reads this native annual plan and this same-account holder renewal.
        $first = $item->product->availablePlans()->first(fn ($plan) => $plan->billing_unit === 'year' && $plan->billing_period === 1)->price('USD');
        $annual = (new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices, $caps))->renewal(
            new \Paymenter\Extensions\Servers\Domains\Models\Domain(['server_id' => $binding->serverId, 'registrar_key' => $binding->registrarKey,
                'account_fingerprint' => $binding->fingerprint, 'tld' => 'net']), 'USD', $clock->now());
        $breakdown = \Paymenter\Extensions\Servers\Domains\Catalogue\TermSupport::lines(number_format((float) $first->total, 2, '.', ''), $annual->retail, $years);
        same($total, $breakdown['total'], $mode.': Landing breakdown agrees with native line total '.$years);
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
// The order path must refresh annual pricing inputs even while the selected-term cost is current.
$ports->apply(Edit::override($binding, 'net', new Intent(sell: true, longest: 10, transfers: true),
    [...PricingRule::DEFAULT, 'markup' => '0.00']), $revision());
$user = commerceUser(); $cart = commerceCart($user);
commerceAdd($cart, $native->product($tlds[0], 'register'), 'refresh-price.net', 2);
$wire = new class implements \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Transport {
    public array $calls = [];
    public function send($environment, $command, array $parameters, string $uid, string $token, int $timeoutMs): string
    {
        same(0, DB::transactionLevel(), 'Price reads remain outside native locks');
        $this->calls[] = [$parameters['ProductType'], $parameters['Years']];
        $price = $parameters['ProductType'] === 16 ? '12.99' : ($parameters['Years'] === 1 ? '19.99' : '9.00');
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done><ProductPrice><ProductEnabled>true</ProductEnabled><Price>'.$price.'</Price></ProductPrice></interface-response>';
    }
};
$registry = new \Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
$registry->register('enom', ['token'], new class implements \Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier {
    public function verify($environment, array $credentials): string { return $credentials['token']; }
}, fn ($b, $credentials) => new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver(
    new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Client($b, $credentials['token'], 'synthetic', $wire), ['net']));
$quoted = new AcquisitionOffers($ports, new AccountBindings($registry), $native);
$admission->fixtureOffers = $eligibility->fixtureOffers = $quoted;
$expire = static function (array $keys) use ($clock, $binding): void {
    foreach (DB::table('domains_prices')->where('server_id', $binding->serverId)->where('tld', 'net')->get() as $row) {
        if (!in_array($row->action.':'.$row->term, $keys, true)) { continue; }
        $raw = OfferState::decode($row->catalogue_observation); $raw['fresh_until'] = $clock->now()->format(DATE_ATOM);
        DB::table('domains_prices')->where('id', $row->id)->update(['catalogue_observation' => OfferState::json($raw)]);
    }
};
$clock->instant = $clock->now()->modify('+1 second');
$expire(['register:1']); app('request')->cookies->set('cart', $cart->ulid);
Illuminate\Support\Once::flush(); $admission->refresh();
same([[10, 1]], $wire->calls, 'Only the stale first registration year is read');
same('30.98', number_format((float) App\Classes\Cart::get()->items->sole()->price->total, 2, '.', ''), 'Cart uses refreshed first year plus current renewal');
$clock->instant = $clock->now()->modify('+6 minutes');
$expire(['register:2', 'renew:1']); Cache::flush();
Illuminate\Support\Once::flush(); $admission->refresh();
same([[10, 1], [10, 2], [16, 1], [10, 1]], $wire->calls, 'Full refresh quotes selected cost and both annual inputs');
same('32.98', number_format((float) App\Classes\Cart::get()->items->sole()->price->total, 2, '.', ''), 'Cart combines both refreshed annual sell prices');
// Native mount can already have cached its daily total; review must precede the refreshed charge.
$orders = Order::count();
$page = commerceCheckout($user, $cart); $page->assertHasNoErrors();
same($orders, Order::count(), 'A changed native total must stop checkout before creating an invoice');
$notice = collect($page->effects['dispatches'] ?? [])->firstWhere('name', 'notify')['params'][0]['message'] ?? '';
expect(str_contains($notice, 'Review the total'), 'Refreshed invoice requires the visible new total: '.$notice);
Illuminate\Support\Once::flush(); $page->call('checkout', ['contact' => commerceOrderContact($user), 'save_account' => false]);
$page->assertHasNoErrors();
$refreshed = Operation::whereHas('domain', fn ($q) => $q->where('domain', 'refresh-price.net'))->sole();
same([2, '32.98', '18.0000', '32.98', '32.98'], [(int) $refreshed->term, $refreshed->amount, $refreshed->cost['estimated'],
    InvoiceItem::findOrFail($refreshed->invoice_item_id)->price, $refreshed->policy['price']['retail']], 'Refreshed cart/invoice agree while selected wholesale and term stay separate: '.json_encode([$refreshed->term, $refreshed->amount, $refreshed->cost, InvoiceItem::findOrFail($refreshed->invoice_item_id)->price]));
// Dependency observation drift must invalidate the saved longer-term quote.
$selection = new \Paymenter\Extensions\Servers\Domains\Data\SaleSelection(...$refreshed->policy['checkout']['catalogue']);
$annual = DB::table('domains_prices')->where('server_id', $binding->serverId)->where('tld', 'net')->where('action', 'register')->where('term', 1)->sole();
$changed = OfferState::decode($annual->catalogue_observation); $changed['total'] = '25.9900';
DB::table('domains_prices')->where('id', $annual->id)->update(['catalogue_observation' => OfferState::json($changed)]);
commerceReject(fn () => $quoted->validate($selection));
DB::table('domains_prices')->where('id', $annual->id)->update(['catalogue_observation' => $annual->catalogue_observation]);
// The mixed retail must still cover the independently quoted selected term's wholesale cost.
$clock->instant = $clock->now()->modify('+1 minute');
$ports->apply(new FeedObserved($binding, 'net', [new Cost('register', 10, true, '200.0000', CostUnit::TermTotal,
    'synthetic', $clock->now(), $now->modify('+1 day'))]), $revision());
same('below_cost', $ports->readOffers(new Query($binding, ['net'], 'register', 10), Context::Staff)[0]->reason, 'Selected-term below-cost guard remains authoritative');
// Nonzero markup and fees round each annual input before combining the purchased term.
$inputs = []; foreach ($costs as $cost) { $inputs[$cost->key()] = $cost; }
foreach ([
    ['32.98', [...PricingRule::DEFAULT, 'markup' => '10.00', 'round_up' => 'ending_99']],
    ['33.00', [...PricingRule::DEFAULT, 'mode' => 'markup_amount', 'markup' => '2.00', 'round_up' => 'half']],
    ['33.98', [...PricingRule::DEFAULT, 'mode' => 'markup_fee_percent', 'markup' => '8.00', 'round_up' => 'ending_99',
        'card_fee' => ['percent' => '2.90', 'fixed' => '0.30']]],
] as [$total, $rule]) {
    same($total, Retail::quote($inputs['register:2'], $rule, $inputs), 'Annual rounding precedes the registration total: '.$rule['mode']);
}
same(null, Retail::quote($inputs['register:2'], PricingRule::DEFAULT), 'Missing annual inputs never invent a registration total');
$nativeSuccessful = true;
echo "Registration retail search/cart/invoice and exact wholesale checks passed.\n";
