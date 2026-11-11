<?php
// Enom 444f31c/b561b9f domain-billing and billing-integration scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reconciliation-fixture.php';
use App\Models\{Invoice, Service};
use Illuminate\Support\Facades\{Artisan, DB, Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation, Price};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HolderPricing, NativeDomainGuard, StaffSettingsReview, StaffSettingsWrites};

$guard = new NativeDomainGuard(new HolderPricing($prices), $clock); $guard->boot(); $guard->boot();
$billing = new DomainBilling($bindings, $guard, $clock,catalogue: $GLOBALS['caps']??null); Queue::fake();
$server = $product->server; $setting = static fn ($key, $value) => $server->settings()->updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'boolean']);
$tld=$tlds[0]->fresh();
$holderBinding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server->id)->sole());
$renewQuery=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($holderBinding,['com'],'renew');
// A separate real completed domain exercises scheduled issue and bill+alert below wholesale.
$bindings->setProcessing($server->id, true, syntheticActivation());
$tld->update(tldColumns(['enabled' => true, 'register_enabled' => true, 'renew_enabled' => true],$tld)); activeFixtureRepublish($prices,$tld); activeFixtureRepublish($native,$tld->id, $now);
activeFixtureSeller($sellers,'com',(int)\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld','com')->value('revision'), $server->id, true, true, $now);
Carbon\Carbon::setTestNow(); Carbon\CarbonImmutable::setTestNow();
[$next] = $prepare(placeholderComFixtureName('billing-below-placeholder.com')); same('succeeded', $reconcile->run($next->id)->state, 'Second real anchor completed');
$clock->instant = new DateTimeImmutable('2027-09-26T12:00:00Z'); $setting('domains_billing_enabled', true);
commerceReject(fn () => $billing->invoice($next->domain_id));
$freshCost=new \Paymenter\Extensions\Servers\Domains\Catalogue\Cost('renew',1,true,'8.0000',\Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+2 days'));
$ports->apply(new \Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($holderBinding,'com',[$freshCost]),$ports->readOffers($renewQuery,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision);
$ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::retainRenewal($holderBinding,'com','1.00'),$ports->readOffers($renewQuery,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision);
$clock->instant = new DateTimeImmutable('2027-09-27T00:00:00Z');
// The site's own TEST account: no new debt while it can't send, whether the site flag or the send permission is what's missing.
$billingOff = static function (?App\Models\User $owner = null) use ($billing, $next): void {
    try { $billing->invoice($next->domain_id, $owner); throw new LogicException('New invoice must refuse'); }
    catch (Paymenter\Extensions\Servers\Domains\Services\RuntimeIneligible $e) { same(Paymenter\Extensions\Servers\Domains\Services\RuntimeReason::BillingOff, $e->reason, 'Site account that cannot send gets no new invoice'); }
};
config(['domains.enom_identity.environment' => 'test', 'domains.enom_writes.renewal' => false, 'domains.enom_catalogue.enabled' => true]); $billingOff();
config(['domains.enom_writes.renewal' => true]); $billingOff();
// A TEST holding on a Live site never sends, so neither billing path may start a new invoice.
config(['domains.enom_identity.environment' => 'live']); $setting('domains_manual_renewals_enabled', true); $billingOff(); $billingOff($user); $setting('domains_manual_renewals_enabled', false);
config(['domains.enom_identity.environment' => null, 'domains.enom_writes.renewal' => false, 'domains.enom_catalogue.enabled' => false]);
$bill = $billing->invoice($next->domain_id);
config(['domains.enom_identity.environment' => 'test']); same($bill->id, $billing->invoice($next->domain_id)->id, 'An issued invoice stays payable with the site flag off'); config(['domains.enom_identity.environment' => null]);
$low = Operation::where('invoice_id', $bill->id)->sole();
same('Domain renewal: billing-below-placeholder.com (1 year, Oct 18, 2027 to Oct 18, 2028)', $bill->items->sole()->description, 'Annual renewal dates');
same('1.00', $low->amount, 'Below-cost renewal invoices without floor, subsidy or hold: '.$low->amount);
same('awaiting_payment', $low->state, 'Below-cost obligation remains billable');
same('below_cost', $next->domain->fresh()->observation['renewal_price_alert']['code'], 'Fresh below-cost margin alerts staff');
same($bill->id, $billing->invoice($next->domain_id)->id, 'Repeated scheduled run is idempotent');
foreach ([App\Jobs\Server\CreateJob::class, App\Jobs\Server\UnsuspendJob::class,
    Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::class] as $job) { Queue::assertNotPushed($job); }
echo "Below-cost native renewal billing passed\n"; $nativeSuccessful=true;
