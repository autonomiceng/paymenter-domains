<?php
// Native no-ID accepted-history completion; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{Disposition, DriverResult, Environment, Evidence, HistoryAcceptance, RegistrationEvidence, RequestBudget, RequestContext, RequestOrigin, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as WireEnvironment, RegistrarRequest, RequestContext as WireContext, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, HistoryAcceptances, OperationReconciliation, RegistrarRegistry};
$sellers->change('com', 2, $tlds[1]->server_id, true, true, $clock->now());
$cart = commerceCart($user); commerceAdd($cart, $native->product($tlds[1], 'register'), 'native-history.com'); commerceCheckout($user, $cart);
$op = Domain::where('domain', 'native-history.com')->sole()->operations()->sole();
Illuminate\Support\Facades\DB::table('invoices')->where('id', $op->invoice_id)->update(['status' => 'paid']);
App\Models\InvoiceTransaction::create(['invoice_id' => $op->invoice_id, 'amount' => $op->amount, 'fee' => '0.00', 'status' => 'succeeded']);
[$claimed, $domain, $binding] = $claims->claim($op->id);
$ctx = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+300 seconds'), new RequestBudget(10), $clock);
$preflight->cost = '8.95';
$sealed = $claims->seal($claimed, $preflight->inspect($claimed, $domain, $ctx));
$claims->finish($sealed, new DriverResult($binding, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
    new Evidence(registration: new RegistrationEvidence(Paymenter\Extensions\Servers\Domains\Services\OperationClaims::query($op, $domain), null, null, null, ['auto_renew']))));
$wire = new class implements DeadlineTransport {
    public int $calls = 0; public array $commands = []; public string $status = 'Active';
    public string $historyId = '901'; public string $historyAction = 'registration'; public string $historyDate = '2026-10-18 05:00:01';
    public string $expiry = '2027-10-18'; public string $auto = 'No'; public bool $quote = true;
    public string $balance = '100.00'; public string $price = '8.95'; public array $omit = []; public $during = null;
    public function send(WireEnvironment $e, WireContext $c, RegistrarRequest $r, string $key): TransportResponse { throw new LogicException; }
    public function sendWithin(WireEnvironment $e, WireContext $c, RegistrarRequest $r, string $key, int $ms): TransportResponse {
        same(0, Illuminate\Support\Facades\DB::transactionLevel(), 'No-ID reads outside locks'); $this->calls++; $this->commands[] = $r->command;
        expect($ms > 0 && $ms <= 25000, 'NameSilo deadline bounded');
        if ($r->command === 'getDomainInfo') { same(['domain' => 'native-history.com'], $r->parameters(), 'Exact account-scoped name read'); }
        if ($this->during) { $hook = $this->during; $this->during = null; $hook($r->command); }
        $reply = match ($r->command) {
            'listOrders' => ['order' => [['order_number' => $this->historyId]]],
            'orderDetails' => ['order_number' => $this->historyId, 'order_date' => $this->historyDate, 'total' => '8.95',
                'order_details' => [['description' => 'native-history.com - '.$this->historyAction, 'years_qty' => '1', 'status' => 'Processed', 'subtotal' => '8.95']]],
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
$registry->register('namesilo', ['token'], $verify, fn ($b, $c) => new Driver(new Client($b, $c['token'], $wire), ['renew'], $clock, 120));
$reconcile = new OperationReconciliation($claims, new AccountBindings($registry), $clock);
$clock->instant = $clock->now()->modify('+600 seconds');
for ($i = 0; $i < 6; $i++) { $result = $reconcile->run($op->id); }
same('pending', $result->state, 'Unaccepted no-ID history stays pending without consuming covered polls');
same(0, $result->policy['reconciliation']['polls'], 'Absent acceptance cannot exhaust recovery');
HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 600, 86400, 'namesilo_registration_v1'));
for ($i = 0; $i < 6; $i++) { $result = $reconcile->run($op->id); }
same(0, $result->policy['reconciliation']['polls'], 'History lag cannot exhaust recovery'); same(0, $wire->calls, 'Uncovered history does no provider work');
$clock->instant = $clock->now()->modify('+301 seconds');
$result = $reconcile->run($op->id); same('succeeded', $result->state, 'Accepted unique history completes no-ID native operation');
same('901', $result->provider_order_id, 'Read-confirmed order persisted'); same('active', $result->domain->status, 'Final ownership');
same(null, $result->attempt['registration']['order_reference'], 'Original missing order ID remains immutable');
same('registration_correction_required', $result->safe_code, 'Correction work retained after financial completion');
$before = $wire->calls; $reconcile->run($op->id); same($before, $wire->calls, 'No replay or repeated provider read after completion');
echo "Native NameSilo no-ID accepted-history unique completion passed\n"; $nativeSuccessful = true;
