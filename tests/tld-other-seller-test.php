<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture: a TLD row whose new orders go to another account says which account, and a terms read that failed
// says so instead of checking with no end.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, Edit, Facts, FactsObserved, FeedObserved, Intent, OfferState, Query, StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Models\Tld;
$fixture = offerCommerceFixture(); extract($fixture); tldPanel(); pinStaffClock($now);
[$second] = offerCommerceSecond($fixture);
Auth::setUser(staffUser(array_keys(Staff::permissions())));
$source = new StaffCatalogue($ports, $clock);
$sales = static fn (Tld $t): array => TldFacts::snapshot($t->fresh(), null, $source)['sales'];
// The second account gets its own .com row and takes new orders for it.
$q = new Query($second, ['com'], 'register');
$rev = static fn () => offerCommerceAsStaff(static fn () => $ports->readOffers($q, Context::Staff)[0]->revision);
$costs = [];
foreach (['register' => '5.0000', 'renew' => '8.0000', 'transfer' => '7.0000'] as $action => $cost) {
    $costs[] = new Cost($action, 1, true, $cost, CostUnit::TermTotal, 'synthetic', $now, $now->modify('+1 day'));
}
$ports->apply(new FeedObserved($second, 'com', $costs), $rev());
$ports->apply(new FactsObserved($second, 'com', new Facts(['register' => [1], 'renew' => [1], 'transfer' => [1]],
    [], true, false, false, 'synthetic', $now, $now->modify('+1 day'))), $rev());
$ports->apply(Edit::override($second, 'com', new Intent(sell: true, transfers: true)), $rev());
$ports->apply(Edit::seller($second, 'com', ['register', 'transfer']), $rev());
$first = Tld::where('server_id', $binding->serverId)->where('tld', 'com')->sole();
same('New orders go to Synthetic second account', $sales($first)['reason'], 'The first account row names the account that takes new orders');
// A failed terms read on the selling account says it failed.
$row = Tld::where('server_id', $second->serverId)->where('tld', 'com')->sole();
DB::table('domains_tlds')->where('id', $row->id)->update(['catalogue_facts' => null]);
same('Set to sell; checking registrar terms', $sales($row)['reason'], 'Terms not read yet are being checked');
DB::table('domains_observation_queues')->updateOrInsert(['server_id' => $second->serverId, 'account_fingerprint' => $second->fingerprint],
    ['offer_work' => OfferState::json(['facts' => ['com' => ['sold_only' => true, 'due_at' => $now->getTimestamp() + 3600, 'tried_at' => $now->getTimestamp(),
        'tries' => 1, 'failed_at' => $now->getTimestamp(), 'failure' => 'read_exception']]])]);
same(TldFacts::TERMS_FAILED, $sales($row)['reason'], 'A failed terms read says it failed');
$nativeSuccessful = true;
