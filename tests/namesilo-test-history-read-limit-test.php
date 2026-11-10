<?php
// A NameSilo TEST registration whose order history cannot confirm it stops reading after the read limit and asks staff to check with NameSilo.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{Discrepancy, Disposition, DriverResult, Environment, Evidence, RegistrationEvidence, RequestBudget, RequestContext, RequestOrigin, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as WireEnvironment, RegistrarRequest, RequestContext as WireContext, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, OperationClaims, OperationReconciliation, RegistrarCheck, RegistrarRegistry};
config(['domains.namesilo_account.environment' => 'test']);
activeFixtureSeller($sellers,'com',(int)\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld','com')->value('revision'), $tlds[1]->server_id, true, true, $clock->now());
$cart = commerceCart($user); commerceAdd($cart, $native->product($tlds[1], 'register'), 'test-history-limit-placeholder.com'); commerceCheckout($user, $cart);
$op = Domain::where('domain', 'test-history-limit-placeholder.com')->sole()->operations()->sole();
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
                'order_details' => [['description' => 'test-history-limit-placeholder.com - Registration', 'years_qty' => '1', 'price' => '8.95', 'status' => 'Processed', 'subtotal' => '8.95']]],
            'getDomainInfo' => ['created' => substr($this->date, 0, 10), 'expires' => $this->expiry, 'status' => 'Active', 'locked' => 'Yes', 'private' => 'No', 'auto_renew' => 'No'],
            default => throw new LogicException('Only named reads permitted'),
        };
        return new TransportResponse(200, json_encode(['request' => ['operation' => $r->command], 'reply' => ['code' => 300, 'detail' => 'success'] + $reply]));
    }
};
// The only order for this name in the history is dated two days before the send, so the history is incomplete.
$wire->date = $sent->setTimezone(new DateTimeZone('UTC'))->modify('-2 days')->format('Y-m-d H:i:s');
$wire->expiry = $sent->modify('+1 year')->format('Y-m-d');
$registry = new RegistrarRegistry;
$verify = new class implements AccountVerifier { public function verify(Environment $e, array $c): string { return $c['token']; } };
$registry->register('namesilo', ['token'], $verify, fn ($b, $c) => new Driver(new Client($b, $c['token'], $wire), ['renew'], $clock, 120),authenticator:new class implements \Paymenter\Extensions\Servers\Domains\Contracts\CredentialAuthenticator {public function authenticate(Environment $environment,array $credentials):void { /* Synthetic local authentication. */ }});
$reconcile = new OperationReconciliation($claims, new AccountBindings($registry), $clock);
$clock->instant = $clock->now()->modify('+600 seconds');
$reads = [];
for ($i = 0; $i < 8; $i++) {
    $before = $wire->calls; $result = $reconcile->run($op->id); $reads[] = $wire->calls - $before;
    $clock->instant = $clock->now()->modify('+400 seconds');
}
expect(min(array_slice($reads, 0, 5)) > 0, 'Each of the first five checks reads the order history');
same([0, 0, 0], array_slice($reads, 5), 'No NameSilo read after the read limit');
same('pending', $result->state, 'The registration stays pending; nothing is sent again');
same(OperationReconciliation::POLL_LIMIT, $result->policy['reconciliation']['polls'], 'Every history read counted toward the read limit');
same('poll_limit_reached', $result->policy['reconciliation']['reason'], 'Staff see that status checks stopped');
same(['awaiting_answer', 'NameSilo', null], [$result->policy['status_check']['outcome'] ?? null, $result->policy['status_check']['registrar'] ?? null, $result->policy['status_check']['next_at'] ?? null], 'Checks stop and wait for NameSilo\'s answer');
same('Ask NameSilo to check this attempt.', RegistrarCheck::step($result)['title'] ?? null, 'Staff are told to ask NameSilo');
same('Registration pending', RegistrarCheck::forCustomer($result)['title'] ?? null, 'The customer keeps the plain pending line');
echo "NameSilo TEST history reads stop at the read limit\n"; $nativeSuccessful = true;
