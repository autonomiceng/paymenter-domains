<?php
// Synthetic adaptations of Enom 444f31c request-gates/cart-funding/provider-disposition scenarios.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/commerce.php';
require __DIR__.'/native-commerce.php';
use App\Models\{Invoice, InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{AdmittedRegistrarDriver, OperationPreflight};
use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation, Capabilities, Capability, DispatchReadiness, Disposition, DriverResult, Evidence, Money, RequestContext, ResultReason, SendState, Support};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, DomainOperations, InvoiceBinding, OperationClaims};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

extract(commerceFixture(true)); $clock = new FakeClock($now);
$transfers = new \Paymenter\Extensions\Servers\Domains\Services\TransferInputs($clock, new \Paymenter\Extensions\Servers\Domains\Services\Refunds($clock));
$transferCheck = new \Paymenter\Extensions\Servers\Domains\Services\TransferPrecheck(new \Paymenter\Extensions\Servers\Domains\Services\Rdap(static fn () => throw new RuntimeException('Synthetic offline RDAP')));
App\Models\Server::find($tlds[0]->server_id)->settings()->create(['key' => 'domains_transfer_endings', 'value' => 'com']);
$admission = new CartAdmission($native, $sellers, new CheckoutEligibility($bindings, $clock, $transferCheck), $clock); $admission->boot();
(new InvoiceBinding($admission, $native, $prices, $clock))->boot();
$user = commerceUser(); $user->update(['email' => 'finance-owner@example.com']);
foreach (['address' => '1 Synthetic Street', 'city' => 'Seattle', 'state' => 'WA', 'zip' => '98101',
    'country' => 'US', 'phone' => '(206) 555-0100'] as $key => $value) {
    $user->properties()->create(['key' => $key, 'name' => $key, 'value' => $value]);
}
$product = $native->product($tlds[0], 'register');
$new = static function (string $name, bool $paid = true, bool $setup = true, string $action = 'register', int $term = 1) use ($user, $product, $native, $tlds, $clock, $transfers): Operation {
    Carbon\Carbon::setTestNow($clock->now()); Carbon\CarbonImmutable::setTestNow($clock->now());
    $cart = commerceCart($user); commerceAdd($cart, $action === 'register' ? $product : $native->product($tlds[0], $action), $name, $term); commerceCheckout($user, $cart);
    $op = Domain::where('domain', $name)->sole()->operations()->sole();
    if (!$setup) { \Paymenter\Extensions\Servers\Domains\Services\ContactSetup::recoveryWrite(static fn () => $op->update(['payload' => array_replace($op->payload, ['setup_required' => true])])); }
    if ($paid) {
        DB::table('invoices')->where('id', $op->invoice_id)->update(['status' => 'paid']);
        InvoiceTransaction::create(['invoice_id' => $op->invoice_id, 'amount' => $op->amount, 'fee' => '0.00', 'status' => 'succeeded']);
    }
    if ($action === 'transfer') {
        $request = Illuminate\Http\Request::create('/internal-transfer-input', 'POST', ['transfer_code' => 'SyntheticOnly']);
        $request->setUserResolver(static fn () => $user); $transfers->submitCode($request, $op->id);
    }
    return $op->fresh();
};
// Raw rows retain invoice/item/payment/credit/job bytes, including native timestamps.
$nativeMoney = static function (): array {
    $rows = [];
    foreach (['invoices', 'invoice_items', 'invoice_transactions', 'credits', 'jobs', 'failed_jobs'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
    }
    return $rows;
};
$preflight = new class($clock) implements OperationPreflight {
    public int $calls = 0; public $during = null; public bool $fail = false; public bool $supported = true;
    public int $ageSeconds = 0; public ?bool $premium = false; public int $freshSeconds = 120; public string $cost = '99.00';
    public function __construct(private $clock) {}
    public function inspect(Operation $op, Domain $domain, RequestContext $context): DispatchReadiness {
        same(0, DB::transactionLevel(), 'Preflight network never under database locks'); $this->calls++;
        $context->claimRead($context->binding);
        if ($this->during) { ($this->during)($op, $domain); }
        if ($this->fail) { throw new RuntimeException('Synthetic preflight exception'); }
        return new DispatchReadiness($context->binding, OperationClaims::query($op, $domain),
            new Capabilities($context->binding, [$op->action => new Capability($this->supported ? Support::Supported : Support::Unsupported, $this->supported ? [1, 3] : [])]),
            new Money($this->cost, 'USD'), true, $this->premium, $this->clock->now()->modify('-'.$this->ageSeconds.' seconds'), $this->clock->now()->modify('+'.$this->freshSeconds.' seconds'), OperationClaims::payloadDigest($op));
    }
};
$writer = new class implements AdmittedRegistrarDriver {
    public ?DomainOperations $engine = null; public int $calls = 0; public bool $fail = false; public $during = null;
    public ?AdmittedOperation $last = null; public ?RequestContext $context = null;
    public bool $consume = true; public bool $wrongBinding = false; public Disposition $disposition = Disposition::Unknown;
    public function register(AdmittedOperation $op, RequestContext $context): DriverResult {
        same(0, DB::transactionLevel(), 'Write never under database locks');
        if ($this->consume) { $this->engine->consume($op, $context, new \Paymenter\Extensions\Servers\Domains\Services\WriteAcceptance($op->binding, true, true, 25, static fn () => true)); commerceReject(fn () => $this->engine->consume($op, $context, new \Paymenter\Extensions\Servers\Domains\Services\WriteAcceptance($op->binding, true, true, 25, static fn () => true))); }
        $context->claimRead($context->binding); $this->calls++; $this->last = $op; $this->context = $context;
        $saved = Operation::where('operation_key', $op->operationKey)->sole();
        same('sealed', $saved->attempt['seal'], 'Seal durably committed before writer');
        expect(isset($saved->funding['seal']), 'Native funding checked again at seal');
        if ($this->during) { ($this->during)($saved); }
        if ($this->fail) { throw new RuntimeException('Synthetic response lost after send'); }
        $binding = $this->wrongBinding ? new Paymenter\Extensions\Servers\Domains\Data\AccountBinding($op->binding->registrarKey, $op->binding->serverId + 999, $op->binding->environment, 'synthetic-other') : $op->binding;
        return new DriverResult($binding, $this->disposition, $this->disposition === Disposition::Unknown ? SendState::PossiblySent : SendState::Sent,
            $this->disposition === Disposition::Unknown ? ResultReason::Unresolved : ResultReason::Acknowledged, new Evidence);
    }
    public function transferIn(AdmittedOperation $op, RequestContext $context): DriverResult { return $this->register($op, $context); }
    public function renew(AdmittedOperation $op, RequestContext $context): DriverResult { throw new LogicException('Renewal gate23 closed.'); }
};
$claims = new OperationClaims($bindings, $clock); $engine = new DomainOperations($claims, $preflight, $writer, $clock); $writer->engine = $engine;
$assertNoSend = static function (Operation $op, string $state = 'held') use ($engine, $writer): Operation {
    $before = $writer->calls; $result = $engine->execute($op->id); same($before, $writer->calls, 'Refused authority sends nothing');
    same($state, $result->state, 'Refusal preserves safe lifecycle'); return $result;
};
