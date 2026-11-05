<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real Livewire checkout, native cart pricing and frozen invoice costs require the installed fixture.
require dirname(__DIR__).'/native-app.php';
require dirname(__DIR__).'/Support/commerce.php';
require dirname(__DIR__).'/Support/native-commerce.php';
require dirname(__DIR__).'/Support/offer-commerce.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts, Context, Query};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, RegistrarDriver};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AttemptEvidence, Capabilities, CostUnit, Disposition, DriverResult, Environment, Evidence, Money, Query as OrderQuery, QuoteEvidence, ReconciliationResult, RequestContext, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AcquisitionOffers, CartAdmission, CheckoutEligibility, InvoiceBinding, PricingRule, RegistrarRegistry};
extract(commerceFixture(true));
$wire = (object)['mode' => 'same', 'quotes' => [], 'availability' => 0];
$registry = new RegistrarRegistry;
$registry->register('enom', ['token'], new class implements AccountVerifier {
    public function verify(Environment $env, array $credentials): string { return $credentials['token']; }
}, static fn($b) => new class($b, $clock, $wire) implements RegistrarDriver {
    public function __construct(private $b, private $clock, private $wire) {}
    public function binding(): AccountBinding { return $this->b; }
    public function accountCapabilities(RequestContext $c): Capabilities { throw new LogicException('Unexpected capabilities'); }
    public function balance(RequestContext $c): DriverResult { throw new LogicException('Unexpected balance'); }
    public function quote(OrderQuery $q, RequestContext $c): DriverResult {
        same(0, DB::transactionLevel(), 'Order quotes run outside native locks'); $c->claimRead($this->b);
        $this->wire->quotes[] = [$q->name, $q->action->value, $q->years];
        if ($this->wire->mode === 'unreadable') { return new DriverResult($this->b, Disposition::Unknown, SendState::Sent, ResultReason::Unverified); }
        $amount = $q->action->value === 'renew' ? '8.0000' : (in_array($this->wire->mode, ['same', 'fresh', 'renew_only'], true) ? ($q->action->value === 'transfer' ? '7.0000' : '6.0000') : '9.0000');
        $binding = $this->wire->mode === 'wrong_account'
            ? new AccountBinding($this->b->registrarKey, $this->b->serverId + 100, $this->b->environment, $this->b->fingerprint) : $this->b;
        return new DriverResult($binding, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
            new Evidence(quote: new QuoteEvidence($q, new Money($amount, 'USD'), CostUnit::TermTotal,
                'enom_product_price', $this->clock->now(), $this->clock->now()->modify('+5 minutes'))));
    }
    public function availability(OrderQuery $q, RequestContext $c): DriverResult {
        same(0, DB::transactionLevel(), 'Availability stays outside native locks'); $c->claimRead($this->b); $this->wire->availability++;
        return new DriverResult($this->b, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged, new Evidence(available: true, premium: false));
    }
    public function readDomain(string $n, RequestContext $c): DriverResult { throw new LogicException('Unexpected domain read'); }
    public function readRenewalDomain(string $n, RequestContext $c): DriverResult { throw new LogicException('Unexpected renewal read'); }
    public function renewalAssessment(OrderQuery $q, RequestContext $c): DriverResult { throw new LogicException('Unexpected renewal assessment'); }
    public function reconcile(AttemptEvidence $a, RequestContext $c): ReconciliationResult { throw new LogicException('Unexpected reconciliation'); }
});
$bindings = new AccountBindings($registry);
// Exercise the actual installed permission and feature callbacks, with synthetic provider I/O only.
app()->instance(AccountBindings::class, $bindings);
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::class, $native);
config(['domains.enom_identity.environment' => 'test', 'domains.enom_catalogue.enabled' => true,
    'domains.enom_writes.registration' => true, 'domains.enom_writes.renewal' => true, 'domains.enom_writes.transfer' => true]);
$binding = AccountBindings::binding(Account::where('server_id', $tlds[0]->server_id)->sole());
foreach ([\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::class, \Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::class] as $feature) {
    App\Models\Server::findOrFail($binding->serverId)->settings()->updateOrCreate(['key' => $feature::KEY],
        ['type' => 'string', 'encrypted' => true, 'value' => json_encode(['fingerprint' => $binding->fingerprint, ...$feature::protocol($binding)])]);
}
\Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::reset();
$ports = CallerPorts::routed();
expect($ports instanceof CallerPorts, 'Real installed customer catalogue composition');
$offers = new AcquisitionOffers($ports, $bindings, $native);
$transfer = new \Paymenter\Extensions\Servers\Domains\Services\TransferPrecheck(new \Paymenter\Extensions\Servers\Domains\Services\Rdap(static fn() => throw new RuntimeException('Synthetic offline lookup')));
$eligibility = new class($bindings, $clock, $transfer) extends CheckoutEligibility { use OfferCommerceRoute; }; $eligibility->fixtureOffers = $offers;
$admission = new class($native, $sellers, $eligibility, $clock) extends CartAdmission { use OfferCommerceRoute; }; $admission->fixtureOffers = $offers; $admission->boot();
$invoiceBinding = new InvoiceBinding($admission, $native, $prices, $clock); app()->instance(InvoiceBinding::class, $invoiceBinding); $invoiceBinding->boot();
$binding = AccountBindings::binding(Account::where('server_id', $tlds[0]->server_id)->sole());
$user = commerceUser();
$quoteAction ??= 'register';
$product = $native->product($tlds[0], $quoteAction);
Illuminate\Support\Facades\Auth::login($user);
expect(!$user->hasPermission(\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW), 'Checkout actor is a customer without staff view');
try { $ports->readOffers(new Query($binding, ['com'], $quoteAction), Context::Staff); throw new RuntimeException('Customer staff read unexpectedly allowed'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { same(403, $e->getStatusCode(), 'Production staff permission is enforced'); }
App\Models\Server::findOrFail($binding->serverId)->settings()->updateOrCreate(['key' => 'domains_transfer_endings'], ['value' => 'com']);
$original = DB::table('domains_prices')->where('server_id', $binding->serverId)->get()->map(fn($r) => (array)$r)->all();
$click = static function($page) use ($user): void { Illuminate\Support\Once::flush(); $page->call('checkout', ['contact' => commerceOrderContact($user), 'save_account' => false]); };
$failures = [];
foreach (($quoteAction === 'register' ? ['fresh', 'renew_only', 'same', 'changed', 'unreadable', 'below_cost', 'ceiling', 'wrong_account'] : ['same', 'changed', 'unreadable', 'below_cost', 'ceiling', 'wrong_account']) as $case) {
    try {
        foreach ($original as $row) { DB::table('domains_prices')->where('id', $row['id'])->update($row); }
        $wire->mode = $case; $wire->quotes = []; $clock->instant = $now;
        $cart = commerceCart($user); $line = commerceAdd($cart, $product, 'quote-'.$quoteAction.'-'.str_replace('_', '-', $case).'-placeholder.com');
        Illuminate\Support\Once::flush();
        $page = Livewire\Livewire::actingAs($user)->withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('use_credits', false);
        $oldTotal = $page->get('total')->total;
        // Only daily prices age; independent registry facts remain current.
        foreach ($original as $row) {
            if ($case === 'fresh' || ($case === 'renew_only' && $row['action'] !== 'renew')) { continue; }
            $cost = json_decode($row['catalogue_observation'], true);
            $cost['source'] = 'enom_bulk_bucket_'.($row['term'] === 3 ? 2 : 1);
            $cost['observed_at'] = $now->modify('-49 hours')->format(DATE_ATOM);
            $cost['fresh_until'] = $now->modify('-1 hour')->format(DATE_ATOM);
            DB::table('domains_prices')->where('id', $row['id'])->update(['catalogue_observation' => json_encode($cost)]);
        }
        $tldRow = (array)DB::table('domains_tlds')->where('id', $tlds[0]->id)->sole();
        $accountRow = (array)DB::table('domains_accounts')->where('server_id', $binding->serverId)->sole();
        if ($case === 'below_cost') { $intent = json_decode($tldRow['catalogue_intent'], true); $intent['pricing_rule'] = [...PricingRule::DEFAULT, 'mode' => 'fixed', 'fixed' => ['register' => '1.00', 'renew' => '10.40', 'transfer' => '1.00']]; DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_intent' => json_encode($intent)]); }
        if ($case === 'ceiling') { $d = json_decode($accountRow['catalogue_defaults'] ?? '{}', true); $d['maxAnnualCost'] = '8.0000'; DB::table('domains_accounts')->where('server_id', $binding->serverId)->update(['catalogue_defaults' => json_encode($d)]); }
        $daily = DB::table('domains_prices')->where('server_id', $binding->serverId)->orderBy('id')->get()->all();
        $nativeDaily = DB::table('prices')->orderBy('id')->get()->all();
        $counts = [App\Models\Order::count(), App\Models\Invoice::count(), Operation::count()];
        if ($case === 'same') { Illuminate\Support\Facades\Cache::forget('domains.checkout.'.$line->checkout_config['domains_check']); }
        $click($page);
        if ($case === 'fresh') { same([], $wire->quotes, 'Fresh customer checkout needs no order price read'); }
        else { expect(count($wire->quotes) > 0, $case.': stale checkout automatically requests an order quote'); }
        if ($case === 'renew_only') { same([['quote-register-renew-only-placeholder.com', 'renew', 1]], $wire->quotes, 'Only the stale follow-on renewal price is re-quoted'); }
        foreach ($wire->quotes as $quoted) { expect(in_array($quoted[1], [$quoteAction, 'renew'], true) && $quoted[2] === 1, 'Exact acquisition action and annual term'); }
        if (in_array($case, ['fresh', 'same', 'renew_only'], true)) {
            expect(!collect($page->effects['dispatches'] ?? [])->contains('name', 'notify'), 'Same-price checkout has no notice: '.json_encode($page->effects['dispatches'] ?? []));
            same($counts[0] + 1, App\Models\Order::count(), 'Same-price checkout has no early return and completes on the first click');
            same([$quoteAction === 'transfer' ? '7.0000' : '6.0000', $quoteAction === 'transfer' ? '9.10' : '7.80', '10.40'], [Operation::latest('id')->first()->cost['estimated'], Operation::latest('id')->first()->policy['price']['retail'], Operation::latest('id')->first()->domain->renewal_policy['retail']], 'Same-price invoice freezes the bound quote and annual renewal');
            if ($case === 'same' && $quoteAction === 'register') {
                $op = Operation::latest('id')->first(); $invoice = App\Models\Invoice::findOrFail($op->invoice_id); $frozen = [$op->policy, $op->cost, $op->amount];
                $pay = new class($bindings, $clock) extends \Paymenter\Extensions\Servers\Domains\Services\PayCheck { use OfferCommerceRoute; }; $pay->fixtureOffers = $offers;
                $clock->instant = $now->modify('+6 minutes'); $beforeQuotes = count($wire->quotes);
                same(null, $pay->stop($invoice), 'Expired cart quote is rechecked before paying the unchanged invoice');
                expect(count($wire->quotes) > $beforeQuotes, 'Payment obtains fresh order evidence after the cart quote expires');
                same($frozen, [$op->fresh()->policy, $op->fresh()->cost, $op->fresh()->amount], 'Payment recheck preserves frozen debt');
                $wire->mode = 'changed'; $clock->instant = $now->modify('+12 minutes');
                same('unavailable', $pay->stop($invoice)['why'] ?? null, 'Later cost rise cannot enlarge the invoice spending ceiling');
                same('pending', $invoice->fresh()->status, 'Refused payment leaves the existing invoice intact');
            }
        } elseif ($case === 'changed') {
            same($counts, [App\Models\Order::count(), App\Models\Invoice::count(), Operation::count()], 'Changed price cannot charge before review');
            same('Your cart changed. Review the total, then check out again.', collect($page->effects['dispatches'] ?? [])->firstWhere('name', 'notify')['params'][0]['message'] ?? '', 'Changed price keeps the existing review notice');
            expect($page->get('total')->total > $oldTotal, 'Changed total is visible before payment');
            $quotes = count($wire->quotes); $click($page);
            same($counts[0] + 1, App\Models\Order::count(), 'Next click accepts the displayed quote: '.json_encode($page->effects['dispatches'] ?? []));
            same($quotes, count($wire->quotes), 'Confirmation reuses the current order quote');
            same(['9.0000', '11.70'], [Operation::latest('id')->first()->cost['estimated'], Operation::latest('id')->first()->policy['price']['retail']], 'Changed quote becomes the frozen cost and staff-rule retail');
        } else {
            same($counts, [App\Models\Order::count(), App\Models\Invoice::count(), Operation::count()], $case.': quote refusal blocks all obligations');
            same($line->id, $cart->items()->sole()->id, $case.': refusal preserves the cart');
            expect(collect($page->effects['dispatches'] ?? [])->contains('name', 'notify'), $case.': refusal has a notice');
        }
        same(json_encode($daily), json_encode(DB::table('domains_prices')->where('server_id', $binding->serverId)->orderBy('id')->get()->all()), $case.': order quotes never alter the daily table');
        same(json_encode($nativeDaily), json_encode(DB::table('prices')->orderBy('id')->get()->all()), $case.': cart quote never republishes native list prices');
        echo $case.": ok\n";
    } catch (Throwable $e) { $failures[] = $case.': '.$e->getMessage(); echo end($failures)."\n"; }
    finally {
        if (isset($tldRow)) { DB::table('domains_tlds')->where('id', $tlds[0]->id)->update($tldRow); }
        if (isset($accountRow)) { DB::table('domains_accounts')->where('server_id', $binding->serverId)->update($accountRow); }
    }
}
expect($failures === [], implode("\n", $failures));
$nativeSuccessful = true;
echo "Automatic stale-cart order quotes: ok\n";
