<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native checkout/contact/tax events and persisted invoice snapshots cannot be proved by a unit fixture.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{Invoice, Order};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Edit, Intent, Query};
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Driver, OfferFeed};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers;
expect(is_file(dirname(__DIR__).'/Domains/Services/AcquisitionOffers.php'), 'Public and native checkout need a shared offer batch adapter');
extract(commerceFixture(true, registrars: ['enom']));
$binding = Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(
    Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $tlds[0]->server_id)->sole());
$eligibility = activeFixtureEligibility($bindings, $clock);
$admission = activeFixtureAdmission($native, $sellers, $eligibility, $clock); $admission->boot();
$binder = new Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding($admission, $native, $prices, $clock);
$binder->boot(); app()->instance(Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class, $binder);
$storefront = activeFixtureStorefront($sellers, $prices, $native, $bindings, $eligibility, $clock);
// Fresh native install: replace fixture evidence with the account bulk table and independently dated metadata.
DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_facts' => null]);
$bulkAt = $now->modify('+1 second'); $clock->instant = $bulkAt;
$wire = new class implements Transport {
    public array $calls = [];
    public function send($environment, $command, array $parameters, string $uid, string $token, int $timeoutMs): string
    {
        same(0, DB::transactionLevel(), 'Account feed and independent facts run outside native locks');
        $this->calls[] = [$command->value, $parameters];
        $header = '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>';
        if ($command === ReadCommand::Tlds) { return $header.'<TLDList><TLD>com</TLD></TLDList></interface-response>'; }
        if ($command === ReadCommand::TldInfo) {
            return $header.'<TLDs><TLD><TLD>com</TLD><Registration><Minimum>1</Minimum><Maximum>10</Maximum><Unit>year</Unit><DNSRequired>false</DNSRequired></Registration><ExtAttributes>false</ExtAttributes><Renewal><AutoRenewOnly>false</AutoRenewOnly></Renewal></TLD></TLDs></interface-response>';
        }
        if ($command === ReadCommand::Attributes) { return $header.'<ResponseCount>0</ResponseCount></interface-response>'; }
        $cost = [1 => '6.00', 2 => '5.50', 5 => '5.00', 10 => '4.50'][$parameters['Years']];
        return $header.'<pricestructure><tld><tld>com</tld><resellerpricereg>'.$cost.'</resellerpricereg><registerenabled>true</registerenabled><resellerpricerenew>8.00</resellerpricerenew><renewenabled>true</renewenabled><resellerpricetran>7.00</resellerpricetran><transferenabled>true</transferenabled></tld></pricestructure></interface-response>';
    }
};
$feed = new OfferFeed(new Driver(new Client($binding, 'account-enom', 'placeholder', $wire), ['com']));
$context = static fn (int $calls) => new RequestContext($binding, RequestOrigin::Background,
    $clock->now()->modify('+130 seconds'), new RequestBudget($calls), $clock);
$annualQuery = new Query($binding, ['com'], 'register');
$annualRevision = fn () => $ports->readOffers($annualQuery, Context::Staff)[0]->revision;
foreach ($feed->prices($context(5)) as $command) { $ports->apply($command, $annualRevision()); }
$ports->apply($feed->facts('com', $context(2)), $annualRevision());
$ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 10, transfers: true)), $annualRevision());
same(['PE_GetRetailPricing', 'PE_GetRetailPricing', 'PE_GetRetailPricing', 'PE_GetRetailPricing', 'GetTLDList', 'GetTLDDetails', 'GetExtAttributes'],
    array_column($wire->calls, 0), 'Five physical feed reads plus two independent facts reads');
$expected = [];
foreach (range(1, 10) as $years) {
    $expected[$years] = PricingRule::decimal(780 + 1040 * ($years - 1));
    $decision = $ports->readOffers(new Query($binding, ['com'], 'register', $years), Context::Staff)[0];
    same([true, $expected[$years]], [$decision->available, $decision->retail], 'Evaluator admits documented priced term '.$years);
}
$search = $storefront->check('bulk-search.com');
same(array_map(static fn ($total) => '$'.$total, $expected), $search['terms'], 'Storefront exposes every term from the one table');
same(true, $ports->readOffers($annualQuery, Context::Public)[0]->available, 'Bulk annual renewal plus registry metadata opens fresh-install .com');
$annualPublic = $storefront->offer('com', 'register', 1);
same(['$7.80', '$10.40'], [$annualPublic['price'], $annualPublic['renew']], 'Annual published acquisition and follow-on renewal');
$public = $annualPublic;
// Fixture writes above are synthetic; customer checkout uses the actual installed permission callback.
config(['domains.enom_identity.environment' => 'test', 'domains.enom_catalogue.enabled' => true,
    'domains.enom_writes.registration' => true, 'domains.enom_writes.renewal' => true]);
$renewal = \Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::class;
App\Models\Server::findOrFail($binding->serverId)->settings()->updateOrCreate(['key' => $renewal::KEY],
    ['type' => 'string', 'encrypted' => true, 'value' => json_encode(['fingerprint' => $binding->fingerprint, ...$renewal::protocol($binding)])]);
\Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::reset();
$offers = new AcquisitionOffers(\Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts::routed(), $bindings, $native);
$admission->fixtureOffers = $eligibility->fixtureOffers = $offers;
$user = commerceUser(); $cart = commerceCart($user);
$line = commerceAdd($cart, $public['product'], 'catalogue-placeholder.com', 1);
foreach (range(2, 10) as $years) {
    $offer = $offers->offer('com', 'register', $years);
    $item = commerceAdd($cart, $offer['product'], 'catalogue-term-'.$years.'.com', $years);
    same($expected[$years], number_format((float) $item->price->total, 2, '.', ''), 'Native cart total matches published term '.$years);
}
$plans = $public['product']->plans()->orderBy('billing_period')->get();
same(range(1, 10), $plans->pluck('billing_period')->map(static fn ($term) => (int) $term)->all(), 'Projection creates all ten matching native plans');
$planIds = $plans->pluck('id')->all();
$ports->apply(Edit::republish($binding, 'com'), $annualRevision());
same($planIds, $public['product']->plans()->orderBy('billing_period')->pluck('id')->all(), 'Republication retains native plan identities');
$token = $line->checkout_config['domains_check']; $before = $reads->calls;
$admission->refresh(); same($before, $reads->calls, 'Current add receipt survives refresh without another provider read');
commerceCheckout($user, $cart);
same([1, 1, 10], [Order::count(), Invoice::count(), Operation::count()], 'One atomic native checkout invoices all ten registration terms');
$op = Operation::where('term', 1)->sole();
same([$binding->serverId, 1, '7.80', '10.40'], [(int)$op->server_id, (int)$op->term, $op->policy['price']['retail'],
    $op->domain->renewal_policy['retail']], 'Acquisition and annual renewal freeze the same admitted batch');
foreach (range(2, 10) as $years) {
    $op = Operation::where('term', $years)->sole();
    $annualCost = $years < 5 ? 55000 : ($years < 10 ? 50000 : 45000);
    same([$years, $expected[$years], PricingRule::decimal($annualCost * $years, 4), '10.40'],
        [(int) $op->term, $op->amount, $op->cost['estimated'], $op->domain->renewal_policy['retail']],
        'Checkout freezes selected bracket cost, exact years, retail and annual renewal '.$years);
    same($expected[$years], App\Models\InvoiceItem::findOrFail($op->invoice_item_id)->price, 'Issued native invoice matches term '.$years);
}
same(7, count($wire->calls), 'Table publication, storefront, cart and checkout require no more feed reads');
same($before, $reads->calls, 'Locked checkout and invoice binding never resolve a provider');
$nativeSuccessful = true;
echo "Bulk annual commerce: ok\n";
