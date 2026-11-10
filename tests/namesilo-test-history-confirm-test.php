<?php
// A NameSilo TEST registration that NameSilo accepted completes from its order history without a saved setting.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, Discrepancy, Disposition, DriverResult, Environment, Evidence, RegistrationEvidence, RequestBudget, RequestContext, RequestOrigin, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver, TestOrderHistory};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as WireEnvironment, RegistrarRequest, RequestContext as WireContext, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, HistoryAcceptances, OperationClaims, OperationReconciliation, RegistrarCheck, RegistrarRegistry};
config(['domains.namesilo_account.environment' => 'test']);
activeFixtureSeller($sellers,'com',(int)\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld','com')->value('revision'), $tlds[1]->server_id, true, true, $clock->now());
$cart = commerceCart($user); commerceAdd($cart, $native->product($tlds[1], 'register'), 'test-history-placeholder.com'); commerceCheckout($user, $cart);
$op = Domain::where('domain', 'test-history-placeholder.com')->sole()->operations()->sole();
Illuminate\Support\Facades\DB::table('invoices')->where('id', $op->invoice_id)->update(['status' => 'paid']);
App\Models\InvoiceTransaction::create(['invoice_id' => $op->invoice_id, 'amount' => $op->amount, 'fee' => '0.00', 'status' => 'succeeded']);
[$claimed, $domain, $binding] = $claims->claim($op->id);
same(Environment::Test, $binding->environment, 'Fixture account is a TEST NameSilo account');
$ctx = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+300 seconds'), new RequestBudget(10), $clock);
$preflight->cost = '8.95';
$sealed = $claims->seal($claimed, $preflight->inspect($claimed, $domain, $ctx), $clock->now());
// NameSilo accepted the order; the follow-up read showed its default nameservers. The reply has no order number.
$claims->finish($sealed, new DriverResult($binding, Disposition::AcceptedWithDiscrepancy, SendState::Sent, ResultReason::Fallback,
    new Evidence(registration: new RegistrationEvidence(OperationClaims::query($op, $domain), null, null, null, ['nameservers'])), [Discrepancy::Nameservers]));
$sent = new DateTimeImmutable($op->fresh()->attempt['dispatch_attempted_at']);
$wire = new class implements DeadlineTransport {
    public int $calls = 0; public string $date = ''; public string $expiry = '';
    public function send(WireEnvironment $e, WireContext $c, RegistrarRequest $r, string $key): TransportResponse { throw new LogicException; }
    public function sendWithin(WireEnvironment $e, WireContext $c, RegistrarRequest $r, string $key, int $ms): TransportResponse {
        $this->calls++;
        $reply = match ($r->command) {
            'listOrders' => ['order' => [['order_number' => '901', 'order_date' => $this->date, 'method' => 'Account Funds', 'total' => '8.95']]],
            'orderDetails' => ['order_number' => '901', 'order_date' => $this->date, 'method' => 'Account Funds', 'total' => '8.95',
                'order_details' => [['description' => 'test-history-placeholder.com - Registration', 'years_qty' => '1', 'price' => '8.95', 'status' => 'Processed', 'subtotal' => '8.95']]],
            'getDomainInfo' => ['created' => substr($this->date, 0, 10), 'expires' => $this->expiry, 'status' => 'Active', 'locked' => 'Yes', 'private' => 'No', 'auto_renew' => 'No'],
            default => throw new LogicException('Only named reads permitted'),
        };
        return new TransportResponse(200, json_encode(['request' => ['operation' => $r->command], 'reply' => ['code' => 300, 'detail' => 'success'] + $reply]));
    }
};
// OTE dates its orders in UTC, one second after the send.
$wire->date = $sent->setTimezone(new DateTimeZone('UTC'))->modify('+1 second')->format('Y-m-d H:i:s');
$wire->expiry = $sent->modify('+1 year')->format('Y-m-d');
$registry = new RegistrarRegistry;
$verify = new class implements AccountVerifier { public function verify(Environment $e, array $c): string { return $c['token']; } };
$registry->register('namesilo', ['token'], $verify, fn ($b, $c) => new Driver(new Client($b, $c['token'], $wire), ['renew'], $clock, 120),authenticator:new class implements \Paymenter\Extensions\Servers\Domains\Contracts\CredentialAuthenticator {public function authenticate(Environment $environment,array $credentials):void { /* Synthetic local authentication. */ }});
$reconcile = new OperationReconciliation($claims, new AccountBindings($registry), $clock);
// Before the history can list the order the request waits for its next check; staff are not told to ask NameSilo.
$clock->instant = $clock->now()->modify('+60 seconds');
$early = $reconcile->run($op->id);
same('pending', $early->state, 'Early check leaves the registration pending');
same(['deferred', 'scheduled'], [$early->policy['status_check']['outcome'] ?? null, $early->policy['status_check']['reason'] ?? null], 'Early check waits for the order history');
expect(isset($early->policy['status_check']['next_at']), 'Early check names its next check');
same(0, $wire->calls, 'Early check makes no NameSilo call');
expect(!str_contains(RegistrarCheck::step($early)['title'] ?? '', 'Ask NameSilo'), 'Staff are not told to ask NameSilo while the history catches up');
// After the listing delay one bounded read confirms the single matching charge and completes the registration.
$clock->instant = $clock->now()->modify('+600 seconds');
$result = $reconcile->run($op->id);
same('succeeded', $result->state, 'Accepted NameSilo TEST registration completes from its order history');
same('901', $result->provider_order_id, 'Order found in the history is saved');
same('active', $result->domain->status, 'Domain becomes active');
same('8.95', $result->cost['actual']['amount'] ?? null, 'Confirmed charge recorded');
same(null, $result->attempt['registration']['order_reference'], 'Original reply stays without an order number');
same(0, App\Models\Server::find($binding->serverId)->settings()->where('key', HistoryAcceptances::KEY)->count(), 'No history setting was saved');
$before = $wire->calls; $reconcile->run($op->id); same($before, $wire->calls, 'No read after completion');
// The built-in values apply only to TEST NameSilo registrations.
expect(TestOrderHistory::acceptance($binding, Action::Register) !== null, 'TEST registration history is built in');
same(null, TestOrderHistory::acceptance($binding, Action::Renew), 'Renewal history is not assumed');
same(null, TestOrderHistory::acceptance(new AccountBinding('namesilo', $binding->serverId, Environment::Live, $binding->fingerprint), Action::Register), 'Live history is never assumed');
config(['domains.namesilo_account.environment' => 'live']);
same(null, TestOrderHistory::acceptance($binding, Action::Register), 'A Live deployment never assumes TEST history');
echo "NameSilo TEST registration completes from its order history\n"; $nativeSuccessful = true;
