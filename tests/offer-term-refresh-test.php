<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/renewal-initialized.php';

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Edit, Intent, OfferWork, Query, TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Driver, OfferFeed};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, NativeCatalogue, PricingRule};

// A retained installation has current prices from before the bracket expansion was installed.
extract(commerceFixture(true, registrars: ['enom'], initialize: static fn () => initializedRenewalFixture()));
$binding = AccountBindings::binding(Account::where('server_id', $tlds[0]->server_id)->sole());
$query = new Query($binding, ['com'], 'register');
$revision = fn () => $ports->readOffers($query, Context::Staff)[0]->revision;
$product = Product::where('slug', NativeCatalogue::slug($tlds[0], 'register'))->sole();
DB::table('domains_prices')->where('server_id', $binding->serverId)->where('action', 'register')->where('term', 3)->delete();
$oldPlan = $product->plans()->where('billing_period', 3)->sole();
$oldPlan->prices()->delete(); DB::table('plans')->where('id', $oldPlan->id)->delete();
$wire = new class implements Transport {
    public array $calls = [];
    public function send($environment, $command, array $parameters, string $uid, string $token, int $timeoutMs): string
    {
        same(0, DB::transactionLevel(), 'Price and metadata reads remain outside native locks');
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
$driver = new Driver(new Client($binding, 'account-enom', 'placeholder', $wire), ['com']);
$context = static fn (int $calls) => new RequestContext($binding, RequestOrigin::Background,
    $clock->now()->modify('+130 seconds'), new RequestBudget($calls), $clock);
$legacy = new OfferFeed($driver, terms: [1 => [1], 2 => [2], 5 => [5], 10 => [10]], expansion: TermSupport::REVIEWED_EVIDENCE['enom']);
$clock->instant = $now->modify('+1 second');
foreach ($legacy->prices($context(5)) as $command) { $ports->apply($command, $revision()); }
$ports->apply($legacy->facts('com', $context(2)), $revision());
$ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 10, transfers: true)), $revision());
$storefront = activeFixtureStorefront($sellers, $prices, $native, $bindings, activeFixtureEligibility($bindings, $clock), $clock);
$terms = fn () => array_keys($storefront->check('retained-terms.com')['terms']);
same([1, 2, 5, 10], $terms(), 'Old saved observations reproduce the demo even with registry and staff limits of ten');
$oldIds = $product->plans()->orderBy('billing_period')->pluck('id', 'billing_period')->all();
same([1, 2, 5, 10], array_keys($oldIds), 'Retained native plans also contain only bracket starts');
$synced = $clock->now()->getTimestamp();
DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->update([
    'offer_feed_tried_at' => $synced, 'offer_feed_succeeded_at' => $synced,
    'offer_work' => json_encode(['feed_due' => $synced + 86400]),
]);
$worker = new OfferWork($ports->writer(), $clock, new AccountReadBudget($clock, 25),
    static fn () => new OfferFeed($driver, expansion: TermSupport::REVIEWED_EVIDENCE['enom']), static fn () => 25);
$clock->instant = $now->modify('+8 hours'); $wire->calls = [];
$worker->accountTurn($binding->serverId);
same([], $wire->calls, 'Installing new feed code does not replace a still-current daily price table');
same([1, 2, 5, 10], $terms(), 'Local republication cannot invent missing saved totals');

// The existing staff Sync prices action queues this same bounded account feed.
$worker->sync($binding);
$worker->accountTurn($binding->serverId);
same(range(1, 10), $terms(), 'Sync prices expands the retained four-term storefront into all ten years');
same([1, 2, 5, 10], array_column(array_column(array_slice($wire->calls, 0, 4), 1), 'Years'), 'Refresh still uses four bracket requests');
same(5, count($wire->calls), 'The refresh needs one suffix list and no additional metadata reads');
$plans = $product->plans()->orderBy('billing_period')->pluck('id', 'billing_period')->all();
same(range(1, 10), array_keys($plans), 'Native cart plans are published for every year');
foreach ($oldIds as $year => $id) { same($id, $plans[$year], 'Refresh preserves the existing plan identity '.$year); }
foreach (range(1, 10) as $year) {
    $decision = $ports->readOffers(new Query($binding, ['com'], 'register', $year), Context::Staff)[0];
    same([true, PricingRule::decimal(780 + 1040 * ($year - 1))], [$decision->available, $decision->retail], 'Refreshed exact retail for year '.$year);
}
$worker->accountTurn($binding->serverId);
same(5, count($wire->calls), 'The completed manual refresh returns to the daily schedule');
$nativeSuccessful = true;
echo "Retained Enom term refresh checks passed.\n";
