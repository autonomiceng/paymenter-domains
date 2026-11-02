<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/accounts.php';

use App\Models\{Invoice, InvoiceItem, InvoiceTransaction, Order, Plan, Product, Server, Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, Edit, Facts, FactsObserved, FeedObserved, Intent, OfferState, Projection, Query, Retail, Revision, UpgradeConversion, Writer};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, Installation, PricingRule, StaffSettingsReview};

// Native SQLite is necessary to prove projection/audit rollback, native identities and frozen
// financial rows. All registrar data and callbacks are synthetic; no transport is installed.
expect(is_file(dirname(__DIR__).'/Domains/Catalogue/Writer.php'), 'Dormant offer writer can apply revisions and project native prices');
(new Installation)->install();
extract(commerceFixture());
[$enom, $namesilo] = $tlds;
$b = CatalogueAcceptances::binding(Account::where('server_id', $enom->server_id)->sole());
$n = CatalogueAcceptances::binding(Account::where('server_id', $namesilo->server_id)->sole());
$converter = new UpgradeConversion;
$converter->batch($b); $converter->batch($n);
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
$apply = static fn ($command) => $writer->apply($command, $revision($command->binding()));
$facts = static fn ($time, array $terms = [1, 3]) => new Facts(['register' => $terms, 'renew' => [1], 'transfer' => [1]],
    [], true, false, false, 'synthetic_facts', $time, $time->modify('+1 day'));
$products = Product::orderBy('id')->pluck('id')->all(); $plans = Plan::orderBy('id')->pluck('id')->all();
$registration = $native->product($enom, 'register'); $annual = $native->annualPlan($registration);
$apply(new FactsObserved($b, 'com', $facts($now)));
$apply(Edit::override($b, 'com', new Intent(sell: true, longest: 3, transfers: true)));
$read = static fn ($binding, $context = Context::Public, $action = 'register', $term = 1) => $writer->readOffers(new Query($binding, ['com'], $action, $term), $context)[0];
same([true, '7.80'], [$read($b)->available, $read($b)->retail], 'Verified offer publishes through the single evaluator');
same('23.40', $read($b, Context::Public, 'register', 3)->retail, 'Existing exact term preserves annual rounding');
same($products, Product::orderBy('id')->pluck('id')->all(), 'Native product IDs survive initial projection');
same($plans, Plan::orderBy('id')->pluck('id')->all(), 'Native plan IDs survive initial projection');
// Same prices on a new revision must cause no native row updates, including timestamps.
$nativeUpdates = []; $lockQueries = []; $captureLocks = false;
DB::listen(static function ($q) use (&$nativeUpdates, &$lockQueries, &$captureLocks): void {
    if ($captureLocks && DB::transactionLevel() > 0 && str_starts_with($q->sql, 'select')) { $lockQueries[] = $q->sql; }
    if (preg_match('/^update ["`](products|plans|prices)["`]/i', $q->sql)) { $nativeUpdates[] = $q->sql; }
});
$expected = $revision($b); $captureLocks = true;
$writer->apply(Edit::republish($b, 'com'), $expected); $captureLocks = false;
$positions = [];
foreach (['categories', 'products', 'plans', 'prices', 'servers', 'domains_accounts', 'domains_sales_policies', 'domains_tlds', 'domains_prices'] as $table) {
    foreach ($lockQueries as $i => $sql) { if (str_contains($sql, 'from "'.$table.'"')) { $positions[] = $i; break; } }
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
$order = Order::create(['user_id' => $owner->id, 'currency_code' => 'USD']);
$service = Service::create(['user_id' => $owner->id, 'order_id' => $order->id, 'product_id' => $registration->id,
    'plan_id' => $annual->id, 'quantity' => 1, 'price' => '10.40', 'currency_code' => 'USD', 'status' => 'active']);
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
expect($stable->matches($writer->apply(new FeedObserved($b, 'com', [$cost('register', 3, '1.0000', $now)]), $stable)), 'Older cost cannot replace current term');
commerceReject(static fn () => $writer->apply(new FeedObserved($b, 'com', [$cost('register', 3, '1.0000', $clock->time)]), $stable));
$apply(new FeedObserved($b, 'com', [$cost('renew', 1, null, $clock->time)]));
same(false, $read($b)->available, 'Positive disabled renewal observation withdraws new acquisitions');
same('10.40', $read($b, Context::Holder, 'renew')->retail, 'Disabled renewal cost retains positive annual holder retail');
$clock->time = $clock->time->modify('+1 minute');
$apply(new FeedObserved($b, 'com', [$cost('renew', 1, '20.0000', $clock->time)]));
$apply(Edit::override($b, 'com', new Intent(sell: true, longest: 3, transfers: true),
    ['mode' => 'fixed', 'markup' => '0', 'round_up' => 'none', 'fixed' => ['register' => '30.00', 'renew' => '10.40', 'transfer' => '30.00']]));
same('10.40', $read($b, Context::Holder, 'renew')->retail, 'Below-cost renewal preserves contractual retail');
$apply(Edit::retainRenewal($b, 'com', '19.95'));
same('19.95', $read($b, Context::Holder, 'renew')->retail, 'Explicit retained renewal command is independent of current costs');
// Defaults/fees invalidate every offer immediately, without an unbounded write or provider read.
$apply(Edit::override($b, 'com', new Intent(sell: true, longest: 3, transfers: true)));
$oldDefaults = $revision($b); $freshFacts = $facts($clock->time);
$apply(new FactsObserved($b, 'com', $freshFacts));
$rule = ['mode' => 'markup_fee_percent', 'markup' => '8.00', 'round_up' => 'ending_99',
    'fixed' => ['register' => null, 'renew' => null, 'transfer' => null], 'card_fee' => ['percent' => '2.90', 'fixed' => '0.30']];
$apply(Edit::defaults($b, 'com', new Intent(defaultSell: true, defaultLongest: 3), $rule));
same(false, $read($b)->available, 'Changed account defaults leave old publications pending');
same('publication_pending', $read($b, Context::Staff)->reason, 'Pending defaults do not authorize new orders');
commerceReject(static fn () => $writer->apply(Edit::republish($b, 'com'), $oldDefaults));
$apply(Edit::republish($b, 'com'));
same(true, $read($b)->available, 'Bounded local republication clears the defaults fence');
$feePrice = $read($b)->retail;
$rule['card_fee']['fixed'] = '5.00';
$apply(Edit::defaults($b, 'com', new Intent(defaultSell: true, defaultLongest: 3), $rule));
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
$nativeSuccessful = true;
echo "Dormant offer writer, revisions, atomic native projection and retained renewal checks passed.\n";
