<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/OfferFixture.php';
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/accounts.php';

use App\Models\{Invoice, InvoiceItem, InvoiceTransaction, Order, Plan, Product, Server, Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, Edit, Facts, FactsObserved, FeedObserved, Intent, OfferState, Projection, Query, Retail, Revision, Writer};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{Installation, PricingRule, StaffSettingsReview};

// Native SQLite is necessary to prove projection/audit rollback, native identities and frozen
// financial rows. All registrar data and callbacks are synthetic; no transport is installed.
expect(is_file(dirname(__DIR__).'/Domains/Catalogue/Writer.php'), 'Dormant offer writer can apply revisions and project native prices');
(new Installation)->install();
extract(commerceFixture(active:true));
[$enom, $namesilo] = $tlds;
$b = \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id', $enom->server_id)->sole());
$n = \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id', $namesilo->server_id)->sole());
// A retained staff master stop is independent of saved/default selling intent.
$enom->update(['enabled' => false]);
$converter = new \Paymenter\Extensions\Servers\Domains\Tests\OfferFixture;
$converter->batch($b); $converter->batch($n);
$convertedIntent = json_decode(DB::table('domains_tlds')->where('id', $enom->id)->value('catalogue_intent'), true);
same(['register', 'renew', 'transfer'], $convertedIntent['stops'], 'Converted staff master stop remains explicit offer intent');
$clock = new class($now) implements Clock {
    public function __construct(public DateTimeImmutable $time) {}
    public function now(): DateTimeImmutable { return $this->time; }
};
$authorized = true;
$authorize = static function ($input, $context) use (&$authorized): void {
    if (!$authorized) { throw new DomainException('Unauthorized offer access.'); }
};
$writer = new Writer(new Projection($native), $clock, $authorize, static fn () => true);
$revision = static function ($binding) use ($writer): Revision {
    return $writer->readOffers(new Query($binding, ['com'], 'register'), Context::Staff)[0]->revision;
};
app()->instance(Clock::class,$clock);
$apply = static function($command)use($writer,$revision){
    if($command instanceof FactsObserved){$command=new FactsObserved($command->binding(),$command->tld(),fixtureNameSiloFacts($command->binding(),$command->tld(),$command->facts));}
    return $writer->apply($command,$revision($command->binding()));
};
$facts = static fn ($time, array $terms = [1, 3]) => new Facts(['register' => $terms, 'renew' => [1], 'transfer' => [1]],
    [], true, false, false, 'synthetic_facts', $time, $time->modify('+1 day'));
// Discover a new offer through observations, without inventing facts or selecting a seller.
$orgRevision = $writer->apply(new FeedObserved($b, 'org', [
    new Cost('register', 1, true, '5.0000', CostUnit::TermTotal, 'synthetic_feed', $now, $now->modify('+1 day')),
    new Cost('renew', 1, true, '6.0000', CostUnit::TermTotal, 'synthetic_feed', $now, $now->modify('+1 day')),
]), new Revision(0, 0, 0));
$orgRead = static fn () => $writer->readOffers(new Query($b, ['org'], 'register'), Context::Public)[0];
same(false, $orgRead()->available, 'New provider discovery grants neither facts nor seller authority');
$orgRevision = $writer->apply(new FactsObserved($b, 'org', $facts($now, [1])), $orgRevision);
$orgRevision = $writer->apply(Edit::override($b, 'org', new Intent(sell: true)), $orgRevision);
$writer->apply(Edit::seller($b, 'org', ['register']), $orgRevision);
same(true, $orgRead()->available, 'Explicit selection publishes a verified new offer');
$org = Paymenter\Extensions\Servers\Domains\Models\Tld::where('server_id', $b->serverId)->where('tld', 'org')->sole();
$orgProduct = $native->product($org, 'register');
$products = Product::orderBy('id')->pluck('id')->all(); $plans = Plan::orderBy('id')->pluck('id')->all();
$registration = $native->product($enom, 'register'); $annual = $native->annualPlan($registration);
$read = static fn ($binding, $context = Context::Public, $action = 'register', $term = 1) => $writer->readOffers(new Query($binding, ['com'], $action, $term), $context)[0];
$apply(new FactsObserved($b, 'com', $facts($now)));
same('staff_stopped', $read($b, Context::Staff)->reason, 'Facts cannot release the converted master stop');
$clock->time = $now->modify('+1 minute');
$apply(new FeedObserved($b, 'com', [new Cost('register', 1, true, '6.0000', CostUnit::TermTotal,
    'synthetic_feed', $clock->time, $clock->time->modify('+1 day'))]));
$apply(Edit::defaults($b, 'com', new Intent(defaultSell: true, defaultLongest: 3, defaultTransfers: true)));
$apply(Edit::republish($b, 'com'));
$writer->apply(Edit::republish($b, 'org'), $orgRead()->revision);
$apply(Edit::seller($b, 'com', ['register', 'transfer']));
foreach (['register', 'transfer'] as $action) {
    same(false, $read($b, Context::Public, $action)->available, 'Automatic changes cannot release master-stopped '.$action);
    same(0, DB::table('prices')->whereIn('plan_id', $native->product($enom, $action)->plans()->pluck('id'))->count(),
        'Master-stopped '.$action.' has no native acquisition prices');
}
same(['register', 'renew', 'transfer'], json_decode(DB::table('domains_tlds')->where('id', $enom->id)->value('catalogue_intent'), true)['stops'],
    'Feed, defaults, seller selection and republication preserve the explicit master stop');
same('10.40', $read($b, Context::Holder, 'renew')->retail, 'Master-stopped holder keeps retained annual renewal retail');
same($annual->id, $native->annualPlan($registration)->id, 'Master stop preserves the native annual holding identity');
// Replacing the override intentionally supplies an empty stop list; background commands never do this.
$apply(Edit::override($b, 'com', new Intent(sell: true, longest: 3, transfers: true)));
same([true, '7.80'], [$read($b)->available, $read($b)->retail], 'Verified offer publishes through the single evaluator');
same('23.40', $read($b, Context::Public, 'register', 3)->retail, 'Existing exact term preserves annual rounding');
same($products, Product::orderBy('id')->pluck('id')->all(), 'Native product IDs survive initial projection');
same($plans, Plan::orderBy('id')->pluck('id')->all(), 'Native plan IDs survive initial projection');
// Same prices on a new revision must cause no native row updates, including timestamps.
$nativeUpdates = []; $lockQueries = []; $captureLocks = false;
DB::listen(static function ($q) use (&$nativeUpdates, &$lockQueries, &$captureLocks): void {
    if ($captureLocks && DB::transactionLevel() > 0) { $lockQueries[] = strtolower($q->sql); }
    if (preg_match('/^update ["`](products|plans|prices)["`]/i', $q->sql)) { $nativeUpdates[] = $q->sql; }
});
$expected = $revision($b); $captureLocks = true;
$writer->apply(Edit::republish($b, 'com'), $expected); $captureLocks = false;
$positions = [];
foreach (['categories', 'products', 'plans', 'prices', 'extensions', 'domains_accounts', 'domains_sales_policies', 'domains_tlds', 'domains_prices'] as $table) {
    foreach ($lockQueries as $i => $sql) { if (preg_match('/\bfrom\s+["`]?'.preg_quote($table, '/').'["`\s]/', $sql)) { $positions[] = $i; break; } }
}
same(9, count($positions), 'Every native/domain lock group is exercised');
$sorted = $positions; sort($sorted); same($sorted, $positions, 'Native locks precede server, account, selection, offer and price locks');
same([], $nativeUpdates, 'Unchanged native rows are not updated during republication');
$stale = $revision($b); $apply(Edit::override($b, 'com', new Intent(sell: false)));
commerceReject(static fn () => $writer->apply(Edit::override($b, 'com', new Intent(sell: true)), $stale));
same(false, $read($b)->available, 'Stale concurrent staff edit cannot undo the committed stop');
same('10.40', $read($b, Context::Holder, 'renew')->retail, 'Stopped holder retains annual renewal retail');
same($annual->id, $native->annualPlan($registration)->id, 'Withdrawal retains the annual holding plan');
$apply(Edit::override($b, 'com', new Intent(sell: true, longest: 3, transfers: true)));
// A persisted paid/native obligation never follows publication or a seller switch.
$owner = commerceUser(); $cart = commerceCart($owner); $item = commerceAdd($cart, $registration, 'example.com');
$unaffectedCart = commerceCart($owner); commerceAdd($unaffectedCart, $orgProduct, 'example.org');
$order = Order::create(['user_id' => $owner->id, 'currency_code' => 'USD']);
$service = Service::withoutEvents(static fn()=>Service::create(['user_id' => $owner->id, 'order_id' => $order->id, 'product_id' => $registration->id,
    'plan_id' => $annual->id, 'quantity' => 1, 'price' => '10.40', 'currency_code' => 'USD', 'status' => 'active']));
$invoice = Invoice::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'paid']);
InvoiceItem::create(['invoice_id' => $invoice->id, 'reference_type' => $service->getMorphClass(), 'reference_id' => $service->id,
    'description' => 'Frozen renewal', 'quantity' => 1, 'price' => '10.40']);
InvoiceTransaction::create(['invoice_id' => $invoice->id, 'amount' => '10.40', 'fee' => '0.00', 'status' => 'succeeded']);
$domain = DB::table('domains_domains')->insertGetId(['server_id' => $b->serverId, 'registrar_key' => 'enom',
    'account_fingerprint' => $b->fingerprint, 'service_id' => $service->id, 'user_id' => $owner->id,
    'domain' => 'example.com', 'reservation_name' => 'example.com', 'sld' => 'example', 'tld' => 'com', 'status' => 'active', 'snapshot' => 'synthetic frozen snapshot']);
DB::table('domains_operations')->insert(['domain_id' => $domain, 'service_id' => $service->id, 'server_id' => $b->serverId, 'registrar_key' => 'enom',
    'account_fingerprint' => $b->fingerprint, 'action' => 'renew', 'state' => 'unknown', 'operation_key' => 'synthetic-paid-renewal',
    'term' => 1, 'amount' => '10.40', 'currency' => 'USD', 'payload' => 'synthetic frozen payload']);
$frozen = static function (): array {
    $out = [];
    foreach (['services', 'invoices', 'invoice_items', 'invoice_transactions', 'domains_domains', 'domains_operations'] as $table) {
        $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    return $out;
};
$obligations = $frozen();
$state = static fn () => [DB::table('domains_tlds')->orderBy('id')->get()->toJson(), DB::table('domains_prices')->orderBy('id')->get()->toJson(),
    Product::orderBy('id')->get()->toJson(), Plan::orderBy('id')->get()->toJson(), DB::table('prices')->orderBy('id')->get()->toJson(),
    Server::find($b->serverId)->settings()->where('key', StaffSettingsReview::KEY)->value('value')];
// Force a late audit failure after price/projection writes; cart side effects must roll back too.
$before = $state(); $setting = Server::find($b->serverId)->settings()->where('key', StaffSettingsReview::KEY)->sole();
DB::statement('CREATE TRIGGER refuse_offer_audit BEFORE UPDATE ON settings WHEN NEW.id = '.$setting->id." BEGIN SELECT RAISE(ABORT, 'synthetic audit refusal'); END");
try { $apply(Edit::override($b, 'com', new Intent(sell: false))); expect(false, 'Audit fault must roll back'); }
catch (Illuminate\Database\QueryException $error) { expect(str_contains($error->getMessage(), 'synthetic audit refusal'), 'Expected injected late failure'); }
DB::statement('DROP TRIGGER refuse_offer_audit');
same($before, $state(), 'Offer, current prices, native plans/prices and audit roll back together');
expect($cart->items()->whereKey($item->id)->exists(), 'Rolled-back offer does not withdraw carts');
$apply(Edit::override($b, 'com', new Intent(sell: false)));
same(0, $cart->items()->count(), 'Committed changes withdraw only affected carts after commit');
same(1, $unaffectedCart->items()->count(), 'Another ending on the same account stays in its cart');
$apply(Edit::override($b, 'com', new Intent(sell: true, longest: 3, transfers: true)));
$oldFact = DB::table('domains_tlds')->where('id', $enom->id)->value('catalogue_facts');
$clock->time = $now->modify('+1 minute');
$cost = static fn ($action, $term, $amount, $time) => new Cost($action, $term, $amount !== null, $amount, CostUnit::TermTotal,
    'synthetic_feed', $time, $time->modify('+1 day'));
$apply(new FeedObserved($b, 'com', [$cost('register', 3, '20.0001', $clock->time)]));
same($oldFact, DB::table('domains_tlds')->where('id', $enom->id)->value('catalogue_facts'), 'Cost observations never refresh facts');
same('26.01', $read($b, Context::Public, 'register', 3)->retail, 'Indivisible exact total uses rational annual rounding');
same('7.80', $read($b)->retail, 'Partial feed retains omitted annual cost');
$stable = $revision($b);
expect($stable->matches($writer->apply(new FeedObserved($b, 'com', []), $stable)), 'Empty feed is a true no-op');
expect($stable->matches($writer->apply(new FactsObserved($b, 'com', $facts($now->modify('-1 hour'))), $stable)), 'Older facts cannot overwrite newer evidence');
$normalized = json_decode($oldFact, true); ksort($normalized['terms']); ksort($normalized);
DB::table('domains_tlds')->where('id', $enom->id)->update(['catalogue_facts' => json_encode($normalized)]);
expect($stable->matches($writer->apply(new FactsObserved($b, 'com', $facts($now)), $stable)), 'Storage key ordering does not create conflicting facts');
expect($stable->matches($writer->apply(new FeedObserved($b, 'com', [$cost('register', 3, '1.0000', $now)]), $stable)), 'Older cost cannot replace current term');
commerceReject(static fn () => $writer->apply(new FeedObserved($b, 'com', [$cost('register', 3, '1.0000', $clock->time)]), $stable));
$apply(new FeedObserved($b, 'com', [$cost('renew', 1, null, $clock->time)]));
same(false, $read($b)->available, 'Positive disabled renewal observation withdraws new acquisitions');
same('10.40', $read($b, Context::Holder, 'renew')->retail, 'Disabled renewal cost retains positive annual holder retail');
$clock->time = $clock->time->modify('+1 minute');
$apply(new FeedObserved($b, 'com', [$cost('renew', 1, '0.0000', $clock->time)]));
same('10.40', $read($b, Context::Holder, 'renew')->retail, 'Nonpositive newly calculated retail retains positive holder retail');
$clock->time = $clock->time->modify('+1 minute');
$apply(new FeedObserved($b, 'com', [$cost('renew', 1, '20.0000', $clock->time)]));
$apply(Edit::override($b, 'com', new Intent(sell: true, longest: 3, transfers: true),
    ['mode' => 'fixed', 'markup' => '0', 'round_up' => 'none', 'fixed' => ['register' => '30.00', 'renew' => '10.40', 'transfer' => '30.00']]));
same('10.40', $read($b, Context::Holder, 'renew')->retail, 'Below-cost renewal preserves contractual retail');
$apply(Edit::retainRenewal($b, 'com', '19.95'));
same('19.95', $read($b, Context::Holder, 'renew')->retail, 'Explicit retained renewal command is independent of current costs');
// Selling defaults fence each account; fixture pricing uses the shared F51 store.
$apply(Edit::override($b, 'com', new Intent(sell: true, longest: 3, transfers: true)));
$oldDefaults = $revision($b); $freshFacts = $facts($clock->time);
$apply(new FactsObserved($b, 'com', $freshFacts));
$rule = ['mode' => 'markup_fee_percent', 'markup' => '8.00', 'round_up' => 'ending_99',
    'fixed' => ['register' => null, 'renew' => null, 'transfer' => null], 'card_fee' => ['percent' => '2.90', 'fixed' => '0.30']];
DB::transaction(static function()use($rule):void{\Paymenter\Extensions\Servers\Domains\Services\BusinessSetting::saveLocked(\Paymenter\Extensions\Servers\Domains\Services\PricingRules::KEY,$rule);\Paymenter\Extensions\Servers\Domains\Services\BusinessSetting::saveLocked(\Paymenter\Extensions\Servers\Domains\Services\CardFee::KEY,$rule['card_fee']);});
$apply(Edit::defaults($b, 'com', new Intent(defaultSell: true, defaultLongest: 3)));
same(false, $read($b)->available, 'Changed account defaults leave old publications pending');
same(false, $orgRead()->available, 'One defaults revision fences every offer on the account');
same(0, $unaffectedCart->items()->count(), 'Defaults withdraw carts across the affected account');
same('publication_pending', $read($b, Context::Staff)->reason, 'Pending defaults do not authorize new orders');
commerceReject(static fn () => $writer->apply(Edit::republish($b, 'com'), $oldDefaults));
$apply(Edit::republish($b, 'com'));
same(true, $read($b)->available, 'Bounded local republication clears the defaults fence');
same(false, $orgRead()->available, 'Republishing one offer cannot clear another offer fence');
$writer->apply(Edit::republish($b, 'org'), $orgRead()->revision);
same(true, $orgRead()->available, 'Independent local fan-out republishes the other offer');
$feePrice = $read($b)->retail;
$rule['card_fee']['fixed'] = '5.00';
DB::transaction(static function()use($rule):void{\Paymenter\Extensions\Servers\Domains\Services\BusinessSetting::saveLocked(\Paymenter\Extensions\Servers\Domains\Services\PricingRules::KEY,$rule);\Paymenter\Extensions\Servers\Domains\Services\BusinessSetting::saveLocked(\Paymenter\Extensions\Servers\Domains\Services\CardFee::KEY,$rule['card_fee']);});
$apply(Edit::defaults($b, 'com', new Intent(defaultSell: true, defaultLongest: 3)));
same(false, $read($b)->available, 'Fee-only change also fences old publications');
$apply(Edit::republish($b, 'com'));
expect(PricingRule::units($read($b)->retail, 2) > PricingRule::units($feePrice, 2), 'Local fee fan-out changes retail without provider reads');
$apply(new FactsObserved($n, 'com', $facts($clock->time, [1])));
$apply(Edit::override($n, 'com', new Intent(sell: true, transfers: true)));
$beforeSwitch = $revision($b);
$otherCart = commerceCart($owner); commerceAdd($otherCart, $registration, 'example.com');
Server::whereKey($b->serverId)->update(['enabled' => false]);
$apply(Edit::seller($n, 'com', ['register', 'transfer']));
same(true, $read($b, Context::Holder, 'renew')->available, 'Disabled deselected account still serves retained holder retail');
same(false, $read($b)->available, 'Old seller becomes unavailable in the same commit');
same(true, $read($n)->available, 'New selected account has its own verified retail');
same(0, $otherCart->items()->count(), 'Seller change withdraws old-account carts after commit');
commerceReject(static fn () => $writer->apply(Edit::seller($b, 'com', ['register']), $beforeSwitch));
same($products, Product::orderBy('id')->pluck('id')->all(), 'Two-account seller switch never reassigns product identities');
same($plans, Plan::orderBy('id')->pluck('id')->all(), 'Two-account seller switch retains exact native plan identities');
same($obligations, $frozen(), 'Paid/native invoices, holding and uncertain operation snapshots are unchanged');
$authorized = false;
commerceReject(static fn () => $read($b, Context::Staff));
commerceReject(static fn () => $read($b, Context::Holder, 'renew'));
commerceReject(static fn () => $writer->apply(Edit::republish($b, 'com'), new Revision(0, 0, 0)));
$authorized = true;
commerceReject(static fn () => $read($n, Context::Locked));
$locked = DB::transaction(static fn () => $writer->readOffers(new Query($n, ['com'], 'register', expected: ['com' => $revision($n)]), Context::Locked)[0]);
same(true, $locked->available, 'Locked admission shares current revisions and evaluator');
DB::transaction(static function () use ($writer, $n): void {
    $d = $writer->readOffers(new Query($n, ['com'], 'register', expected: ['com' => new Revision(0, 0, 0)]), Context::Locked)[0];
    same(false, $d->available, 'Locked admission refuses stale revisions');
});
// Configured hidden endings close every acquisition path, while existing holdings can renew.
Server::whereKey($b->serverId)->update(['enabled' => true]);
$apply(Edit::seller($b, 'com', ['register', 'transfer']));
same(true, $read($b)->available, 'Hidden-ending fixture starts selected, ready and published');
$holderRetail = $read($b, Context::Holder, 'renew')->retail;
$hiddenConfig = config('domains.hidden_endings');
config(['domains.hidden_endings' => ['.COM']]);
foreach ([Context::Public, Context::Staff] as $context) {
    foreach (['register', 'transfer'] as $action) {
        same(false, $read($b, $context, $action)->available, 'Configured hidden ending refuses '.$context->name.' '.$action);
    }
}
same('staff_stopped', $read($b, Context::Staff)->reason, 'Hidden ending is an explicit acquisition stop');
$hiddenLocked = DB::transaction(static fn () => $writer->readOffers(new Query($b, ['com'], 'register',
    expected: ['com' => $revision($b)]), Context::Locked)[0]);
same(false, $hiddenLocked->available, 'Configured hidden ending refuses locked admission with current revisions');
$apply(Edit::republish($b, 'com'));
foreach (['register', 'transfer'] as $action) {
    same(0, DB::table('prices')->whereIn('plan_id', $native->product($enom, $action)->plans()->pluck('id'))->count(),
        'Hidden-ending republication withdraws native '.$action.' prices');
}
same([true, $holderRetail, 'retained_renewal'], [$read($b, Context::Holder, 'renew')->available,
    $read($b, Context::Holder, 'renew')->retail, $read($b, Context::Holder, 'renew')->warning],
    'Existing holder retains annual renewal retail for a hidden ending');
same($products, Product::orderBy('id')->pluck('id')->all(), 'Hidden-ending publication preserves native product identities');
same($plans, Plan::orderBy('id')->pluck('id')->all(), 'Hidden-ending publication preserves native plan identities');
same($obligations, $frozen(), 'Hidden ending preserves existing service, financial and operation snapshots');
config(['domains.hidden_endings' => $hiddenConfig]);
// Missing offers still participate in the real defaults and cross-account selection fences.
$defaultsRevision = $apply(Edit::defaults($b, 'com', new Intent(defaultSell: true)));
$orgSelection = $orgRead()->revision->selection;
expect($defaultsRevision->defaults > 0 && $orgSelection > 0, 'Discovery fixtures have advanced defaults and seller selection');
$missingDefault = $writer->readOffers(new Query($b, ['net'], 'register'), Context::Staff)[0];
$missingSelected = $writer->readOffers(new Query($n, ['org'], 'register'), Context::Staff)[0];
$decisionState = static fn ($d) => [$d->available, $d->retail, $d->revision->offer, $d->revision->defaults, $d->revision->selection];
same([[false, null, 0, $defaultsRevision->defaults, 0], [false, null, 0, $revision($n)->defaults, $orgSelection]],
    [$decisionState($missingDefault), $decisionState($missingSelected)],
    'Absent discovery reads preserve real defaults and another account seller selection without availability');
foreach ([[$b, 'net', $missingDefault], [$n, 'org', $missingSelected]] as [$binding, $ending, $missing]) {
    $created = $writer->apply(new FeedObserved($binding, $ending, [new Cost('register', 1, true, '5.0000',
        CostUnit::TermTotal, 'synthetic_feed', $clock->time, $clock->time->modify('+1 day'))]), $missing->revision);
    same([1, $missing->revision->defaults, $missing->revision->selection],
        [$created->offer, $created->defaults, $created->selection], 'Read revision authorizes first discovery of '.$ending);
    $discovered = $writer->readOffers(new Query($binding, [$ending], 'register'), Context::Staff)[0];
    expect($created->matches($discovered->revision), 'Discovered offer exposes its committed revision');
    same(false, $discovered->available, 'Discovery grants no facts or seller permission for '.$ending);
}
$orgPolicy = DB::table('domains_sales_policies')->where('tld', 'org')->sole();
same([$b->serverId, $orgSelection], [(int) $orgPolicy->server_id, (int) $orgPolicy->revision],
    'Other-account discovery cannot change the existing seller or its revision');
same($obligations, $frozen(), 'Discovery preserves existing service, financial and operation snapshots');
$nativeSuccessful = true;
echo "Dormant offer writer, revisions, atomic native projection and retained renewal checks passed.\n";
