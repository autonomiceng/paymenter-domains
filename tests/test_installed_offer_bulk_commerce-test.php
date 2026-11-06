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
extract(offerCommerceFixture());
// Fresh native install: replace fixture evidence with the account bulk table and independently dated metadata.
DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_facts' => null]);
$bulkAt = $now->modify('+1 second'); $clock->instant = $bulkAt;
$bulkCosts = [];
foreach ([['register', 1, '6.0000'], ['register', 3, '18.0000'], ['renew', 1, '8.0000'], ['transfer', 1, '7.0000']] as [$action, $term, $total]) {
    $bulkCosts[] = new Paymenter\Extensions\Servers\Domains\Catalogue\Cost($action, $term, true, $total,
        Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal, 'enom_bulk_bucket_'.($term === 3 ? 2 : 1), $bulkAt, $bulkAt->modify('+48 hours'));
}
$annualQuery = new Query($binding, ['com'], 'register');
$annualRevision = fn () => offerCommerceAsStaff(static fn () => $ports->readOffers($annualQuery, Context::Staff)[0]->revision);
$ports->apply(new Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($binding, 'com', $bulkCosts), $annualRevision());
$ports->apply(new Paymenter\Extensions\Servers\Domains\Catalogue\FactsObserved($binding, 'com',
    new Paymenter\Extensions\Servers\Domains\Catalogue\Facts(['register' => [1, 3]], [], true, false, false,
        'enom_tld_facts', $bulkAt, $bulkAt->modify('+30 days'))), $annualRevision());
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
$token = $line->checkout_config['domains_check']; $before = $reads->calls;
$admission->refresh(); same($before, $reads->calls, 'Current add receipt survives refresh without another provider read');
commerceCheckout($user, $cart);
same([1, 1, 1], [Order::count(), Invoice::count(), Operation::count()], 'Real native checkout creates one atomic obligation');
$op = Operation::firstOrFail();
same([$binding->serverId, 1, '7.80', '10.40'], [(int)$op->server_id, (int)$op->term, $op->policy['price']['retail'],
    $op->domain->renewal_policy['retail']], 'Acquisition and annual renewal freeze the same admitted batch');
same($before, $reads->calls, 'Locked checkout and invoice binding never resolve a provider');
$nativeSuccessful = true;
echo "Bulk annual commerce: ok\n";
