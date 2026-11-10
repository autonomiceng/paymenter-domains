<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture: a change to Default for new TLDs never takes a TLD off sale while the account republishes.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Edit, Intent, Query};
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;
extract(offerCommerceFixture());
$q = new Query($binding, ['com'], 'register');
$rev = static fn () => offerCommerceAsStaff(static fn () => $ports->readOffers($q, Context::Staff)[0]->revision);
$onSale = static function () use ($storefront): bool {
    try { return $storefront->offer('com', 'register', 1) !== null; } catch (DomainException|LogicException) { return false; }
};
expect($onSale(), '.com is for sale before the default changes');
// A wider default keeps every published offer for sale before the account republishes.
$ports->apply(Edit::defaults($binding, 'com', new Intent(defaultLongest: 3, defaultTransfers: true)), $rev());
expect($onSale(), 'A wider default keeps .com for sale before it republishes');
$ports->apply(Edit::defaults($binding, 'com', new Intent(defaultLongest: 2, defaultTransfers: true)), $rev());
expect($onSale(), 'A second default change before republishing keeps .com for sale');
$ports->apply(Edit::republish($binding, 'com'), $rev());
expect($onSale(), '.com stays for sale once it republishes');
// A narrower default applies at once, and putting it back restores sales at once.
$ports->apply(Edit::defaults($binding, 'com', new Intent(defaultLongest: 2, defaultTransfers: true, maxAnnualCost: PricingRule::wholesale('0.01'))), $rev());
commerceReject(static fn () => $storefront->offer('com', 'register', 1));
$ports->apply(Edit::defaults($binding, 'com', new Intent(defaultLongest: 2, defaultTransfers: true)), $rev());
expect($onSale(), 'Putting the default back sells .com again without waiting to republish');
// A TLD the old default did not sell waits for its republish before it goes on sale.
$ports->apply(Edit::defaults($binding, 'com', new Intent(defaultSell: false, defaultLongest: 2, defaultTransfers: true)), $rev());
$ports->apply(Edit::override($binding, 'com', new Intent(longest: 2, transfers: true)), $rev());
expect(!$onSale(), '.com follows a default that does not sell it');
$ports->apply(Edit::defaults($binding, 'com', new Intent(defaultSell: true, defaultLongest: 2, defaultTransfers: true)), $rev());
expect(!$onSale(), 'A TLD the old default did not sell waits for its republish');
same('publication_pending', offerCommerceAsStaff(static fn () => $ports->readOffers($q, Context::Staff)[0]->reason), 'Staff see it waiting to publish');
$ports->apply(Edit::republish($binding, 'com'), $rev());
expect($onSale(), 'Once republished, the wider default sells it');
$nativeSuccessful = true;
