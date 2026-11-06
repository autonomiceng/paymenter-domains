<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/OfferFixture.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Edit, Facts, FactsObserved, Intent, Query};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionOffers, CartAdmission, CheckoutEligibility, Installation, InvoiceBinding, Storefront};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;

/** Only this synthetic fixture overrides the fixed legacy routing seam. No installed switch exists. */
trait OfferCommerceRoute
{
    public AcquisitionOffers $fixtureOffers;
    protected function catalogue(): ?AcquisitionOffers { return $this->fixtureOffers; }
}
/** The installed permission rule: a Staff read needs a signed-in user with the Domains view grant. */
function offerCommerceAuthorize(): Closure
{
    return static function ($value, ?Context $context = null): void { if ($context === Context::Staff) { Staff::requireView(); } };
}
/** Staff arranging steps sign in a Domains viewer, then restore the customer (or nobody). */
function offerCommerceAsStaff(callable $body): mixed
{
    $previous = Auth::user();
    $staff = App\Models\User::firstOrCreate(['email' => 'offer-commerce-staff@example.test'], ['first_name' => 'Synthetic', 'last_name' => 'Staff',
        'password' => 'synthetic-password', 'role_id' => App\Models\Role::firstOrCreate(['name' => 'Synthetic Domains viewer'], ['permissions' => [Staff::VIEW]])->id]);
    Auth::setUser($staff);
    try { return $body(); } finally { $previous !== null ? Auth::setUser($previous) : Auth::forgetUser(); }
}
function offerCommerceFixture(): array
{
    // One protected composition: every returned adapter and owner registration uses the installed staff rule.
    (new Installation)->install(); extract(offerCommerceAsStaff(static fn () => commerceFixture(true, active: true, authorize: offerCommerceAuthorize())));
    $binding = \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id', $tlds[0]->server_id)->sole());
    foreach (Account::all() as $account) { (new \Paymenter\Extensions\Servers\Domains\Tests\OfferFixture)->batch(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account)); }
    $q = new Query($binding, ['com'], 'register');
    $rev = static fn () => offerCommerceAsStaff(static fn () => $ports->readOffers($q, Context::Staff)[0]->revision);
    $ports->apply(new FactsObserved($binding, 'com', new Facts(['register' => [1, 3], 'renew' => [1], 'transfer' => [1]],
        [], true, false, false, 'synthetic', $now, $now->modify('+1 day'))), $rev());
    $ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 3, transfers: true)), $rev());
    $ports->apply(Edit::seller($binding, 'com', ['register', 'transfer']), $rev());
    App\Models\Server::find($binding->serverId)->settings()->create(['key' => 'domains_transfer_endings', 'value' => 'com']);
    $transfer = new Paymenter\Extensions\Servers\Domains\Services\TransferPrecheck(new Paymenter\Extensions\Servers\Domains\Services\Rdap(static fn () => throw new RuntimeException('Synthetic offline lookup')));
    $eligibility = new class($bindings, $clock, $transfer) extends CheckoutEligibility { use OfferCommerceRoute; };
    $eligibility->fixtureOffers = $offers;
    $admission = new class($native, $sellers, $eligibility, $clock) extends CartAdmission { use OfferCommerceRoute; };
    $admission->fixtureOffers = $offers; $admission->boot();
    $invoiceBinding = new InvoiceBinding($admission, $native, $prices, $clock); app()->instance(InvoiceBinding::class,$invoiceBinding); $invoiceBinding->boot();
    $storefront = new class($sellers, $prices, $native, $bindings, $eligibility, $clock) extends Storefront { use OfferCommerceRoute; };
    $storefront->fixtureOffers = $offers;
    return compact('bindings', 'prices', 'sellers', 'native', 'now', 'tlds', 'reads', 'clock', 'ports', 'binding',
        'offers', 'eligibility', 'admission', 'invoiceBinding', 'storefront');
}

/** A second selected Enom account avoids pretending NameSilo enrollment was accepted. */
function offerCommerceSecond(array $fixture,string $ending='net'): array
{
    extract($fixture);
    $server = App\Models\Server::create(['name' => 'Synthetic second account', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]);
    $b = $bindings->configure($server->id, 'enom', Paymenter\Extensions\Servers\Domains\Data\Environment::Test, ['token' => 'second-enom']);
    $server->settings()->updateOrCreate(['key' => 'domains_pricing_default_rule'],
        ['type' => 'array', 'encrypted' => false, 'value' => Paymenter\Extensions\Servers\Domains\Services\PricingRule::DEFAULT]);
    $bindings->setProcessing($server->id, true, syntheticActivation());
    // New feed/facts commands create an independent converted offer and permanent native projection.
    $q = new Query($b, [$ending], 'register');
    $rev = static fn () => offerCommerceAsStaff(static fn () => $ports->readOffers($q, Context::Staff)[0]->revision);
    $costs = [];
    foreach (['register' => '5.0000', 'renew' => '8.0000', 'transfer' => '7.0000'] as $action => $cost) {
        $costs[] = new Paymenter\Extensions\Servers\Domains\Catalogue\Cost($action, 1, true, $cost,
            Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal, 'synthetic', $now, $now->modify('+1 day'));
    }
    $ports->apply(new Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($b, $ending, $costs), $rev());
    $ports->apply(new FactsObserved($b, $ending, new Facts(['register' => [1], 'renew' => [1], 'transfer' => [1]],
        [], true, false, false, 'synthetic', $now, $now->modify('+1 day'))), $rev());
    $ports->apply(Edit::override($b, $ending, new Intent(sell: true, transfers: true)), $rev());
    $ports->apply(Edit::seller($b, $ending, ['register', 'transfer']), $rev());
    return [$b, $offers->offer($ending, 'register', 1)];
}

/** Synthetic authentication proves a same-identity credential rotation, without a provider. */
function offerCommerceRotate($binding, string $token): void
{
    $registry = new Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
    $registry->register('enom', ['token'], new class(Account::where('server_id', $binding->serverId)->sole()->stable_identity) implements Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier {
        public function __construct(private string $identity) {}
        public function verify($env, array $credentials): string { return $this->identity; }
    }, static fn () => throw new RuntimeException('Rotation never resolves a reader'));
    $bindings = new Paymenter\Extensions\Servers\Domains\Services\AccountBindings($registry);
    $bindings->configure($binding->serverId, 'enom', $binding->environment, ['token' => $token]);
    $bindings->setProcessing($binding->serverId, true, syntheticActivation());
}
