<?php
// Paid NameSilo registration sealed as accepted without an order ID; MIT, Copyright (c) 2026 Autonomic Engineering.
// Setup only, 600 seconds after the send: reconciliation-namesilo-test.php owns the assertions on this state.
// The wire fake keeps its per-read lock and request guards for every test that reads through it.
declare(strict_types=1);
require __DIR__.'/operation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{Disposition, DriverResult, Environment, Evidence, RegistrationEvidence, RequestBudget, RequestContext, RequestOrigin, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as WireEnvironment, RegistrarRequest, RequestContext as WireContext, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, OperationReconciliation, RegistrarRegistry};
activeFixtureSeller($sellers,'com',(int)\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld','com')->value('revision'), $tlds[1]->server_id, true, true, $clock->now());
$cart = commerceCart($user); commerceAdd($cart, $native->product($tlds[1], 'register'), 'native-history-placeholder.com'); commerceCheckout($user, $cart);
$op = Domain::where('domain', 'native-history-placeholder.com')->sole()->operations()->sole();
Illuminate\Support\Facades\DB::table('invoices')->where('id', $op->invoice_id)->update(['status' => 'paid']);
App\Models\InvoiceTransaction::create(['invoice_id' => $op->invoice_id, 'amount' => $op->amount, 'fee' => '0.00', 'status' => 'succeeded']);
[$claimed, $domain, $binding] = $claims->claim($op->id);
$ctx = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+300 seconds'), new RequestBudget(10), $clock);
$preflight->cost = '8.95';
$sealed = $claims->seal($claimed, $preflight->inspect($claimed, $domain, $ctx), $clock->now());
$claims->finish($sealed, new DriverResult($binding, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
    new Evidence(registration: new RegistrationEvidence(Paymenter\Extensions\Servers\Domains\Services\OperationClaims::query($op, $domain), null, null, null, ['auto_renew']))));
$wire = new class implements DeadlineTransport {
    public int $calls = 0; public array $commands = []; public string $status = 'Active';
    public string $historyId = '901'; public string $historyAction = 'registration'; public string $historyDate = '2026-10-18 05:00:01';
    public string $expiry = '2027-10-18'; public string $auto = 'No'; public bool $quote = true;
    public string $balance = '100.00'; public string $price = '8.95'; public array $omit = []; public $during = null;
    public array $orderCredit = [];
    public function send(WireEnvironment $e, WireContext $c, RegistrarRequest $r, string $key): TransportResponse { throw new LogicException; }
    public function sendWithin(WireEnvironment $e, WireContext $c, RegistrarRequest $r, string $key, int $ms): TransportResponse {
        same(0, Illuminate\Support\Facades\DB::transactionLevel(), 'No-ID reads outside locks'); $this->calls++; $this->commands[] = $r->command;
        expect($ms > 0 && $ms <= 25000, 'NameSilo deadline bounded');
        if ($r->command === 'getDomainInfo') { same(['domain' => 'native-history-placeholder.com'], $r->parameters(), 'Exact account-scoped name read'); }
        if ($this->during) { $hook = $this->during; $this->during = null; $hook($r->command); }
        $reply = match ($r->command) {
            'listOrders' => ['order' => [['order_number' => $this->historyId]]],
            'orderDetails' => ['order_number' => $this->historyId, 'order_date' => $this->historyDate, 'total' => '8.95',
                'order_details' => [['description' => 'native-history-placeholder.com - '.$this->historyAction, 'years_qty' => '1', 'status' => 'Processed', 'subtotal' => '8.95'] + $this->orderCredit]],
            'getDomainInfo' => ['created' => '2026-10-18', 'expires' => $this->expiry, 'status' => $this->status, 'locked' => 'Yes', 'private' => 'No', 'auto_renew' => $this->auto],
            'getPrices' => [$this->quote ? 'com' : 'net' => ['registration' => '8.95', 'renew' => $this->price, 'transfer' => '8.95']],
            'getAccountBalance' => ['balance' => $this->balance],
            default => throw new LogicException('Only named reads permitted'),
        };
        if ($r->command === 'getDomainInfo') { foreach ($this->omit as $field) { unset($reply[$field]); } }
        return new TransportResponse(200, json_encode(['request' => ['operation' => $r->command], 'reply' => ['code' => 300, 'detail' => 'synthetic'] + $reply]));
    }
};
$registry = new RegistrarRegistry;
$verify = new class implements AccountVerifier { public function verify(Environment $e, array $c): string { return $c['token']; } };
$registry->register('namesilo', ['token'], $verify, fn ($b, $c) => new Driver(new Client($b, $c['token'], $wire), ['renew'], $clock, 120),authenticator:new class implements \Paymenter\Extensions\Servers\Domains\Contracts\CredentialAuthenticator {public function authenticate(Environment $environment,array $credentials):void { /* Synthetic local authentication. */ }});
$reconcile = new OperationReconciliation($claims, new AccountBindings($registry), $clock);
$clock->instant = $clock->now()->modify('+600 seconds');
