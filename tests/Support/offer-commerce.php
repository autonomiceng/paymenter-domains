<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts, Context, Edit, Facts, FactsObserved, Intent, Projection, Query, UpgradeConversion};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionOffers, CartAdmission, CatalogueAcceptances, CheckoutEligibility, Installation, InvoiceBinding, Storefront};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

/** Only this synthetic fixture overrides the fixed legacy routing seam. No installed switch exists. */
trait OfferCommerceRoute
{
    public AcquisitionOffers $fixtureOffers;
    protected function catalogue(): ?AcquisitionOffers { return $this->fixtureOffers; }
}
function offerCommerceFixture(): array
{
    (new Installation)->install(); extract(commerceFixture(true)); $clock = new FakeClock($now);
    $ports = new CallerPorts(new Projection($native), $clock, $bindings, static fn () => null, static fn () => true);
    $binding = CatalogueAcceptances::binding(Account::where('server_id', $tlds[0]->server_id)->sole());
    (new UpgradeConversion)->batch($binding);
    $q = new Query($binding, ['com'], 'register');
    $rev = static fn () => $ports->readOffers($q, Context::Staff)[0]->revision;
    $ports->apply(new FactsObserved($binding, 'com', new Facts(['register' => [1, 3], 'renew' => [1], 'transfer' => [1]],
        [], true, false, false, 'synthetic', $now, $now->modify('+1 day'))), $rev());
    $ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 3, transfers: true)), $rev());
    $ports->apply(Edit::seller($binding, 'com', ['register', 'transfer']), $rev());
    $offers = new AcquisitionOffers($ports, $bindings, $native);
    $eligibility = new class($bindings, $clock) extends CheckoutEligibility { use OfferCommerceRoute; };
    $eligibility->fixtureOffers = $offers;
    $admission = new class($native, $sellers, $eligibility, $clock) extends CartAdmission { use OfferCommerceRoute; };
    $admission->fixtureOffers = $offers; $admission->boot();
    $invoiceBinding = new InvoiceBinding($admission, $native, $prices, $clock); $invoiceBinding->boot();
    $storefront = new class($sellers, $prices, $native, $bindings, $eligibility, $clock) extends Storefront { use OfferCommerceRoute; };
    $storefront->fixtureOffers = $offers;
    return compact('bindings', 'prices', 'sellers', 'native', 'now', 'tlds', 'reads', 'clock', 'ports', 'binding',
        'offers', 'eligibility', 'admission', 'invoiceBinding', 'storefront');
}
