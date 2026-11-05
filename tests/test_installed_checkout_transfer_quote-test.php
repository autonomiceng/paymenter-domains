<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$quoteAction = 'transfer';
require __DIR__.'/Support/checkout-order-quote.php';

$nativeSuccessful = false;
$clock->instant = $now;
foreach ($original as $row) { DB::table('domains_prices')->where('id', $row['id'])->update($row); }
// Inspect the real RequestContext at the synthetic registrar boundary, without waiting.
$deadline = (object) ['value' => null];
$registry = new \Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
$registry->register('enom', ['token'], new class implements \Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier {
    public function verify($env, array $credentials): string { return $credentials['token']; }
}, static fn ($b) => new class($b, $clock, $deadline) implements \Paymenter\Extensions\Servers\Domains\Contracts\RegistrarDriver {
    public function __construct(private $b, private $clock, private $deadline) {}
    public function binding(): \Paymenter\Extensions\Servers\Domains\Data\AccountBinding { return $this->b; }
    public function availability($q, $c): \Paymenter\Extensions\Servers\Domains\Data\DriverResult {
        $this->deadline->value = $c->deadline; throw new RuntimeException('Captured transfer deadline');
    }
    public function accountCapabilities($c): \Paymenter\Extensions\Servers\Domains\Data\Capabilities { throw new LogicException; }
    public function balance($c): \Paymenter\Extensions\Servers\Domains\Data\DriverResult { throw new LogicException; }
    public function quote($q, $c): \Paymenter\Extensions\Servers\Domains\Data\DriverResult { throw new LogicException; }
    public function readDomain($n, $c): \Paymenter\Extensions\Servers\Domains\Data\DriverResult { throw new LogicException; }
    public function readRenewalDomain($n, $c): \Paymenter\Extensions\Servers\Domains\Data\DriverResult { throw new LogicException; }
    public function renewalAssessment($q, $c): \Paymenter\Extensions\Servers\Domains\Data\DriverResult { throw new LogicException; }
    public function reconcile($a, $c): \Paymenter\Extensions\Servers\Domains\Data\ReconciliationResult { throw new LogicException; }
});
$boundedBindings = new \Paymenter\Extensions\Servers\Domains\Services\AccountBindings($registry);
$bounded = new class($boundedBindings, $clock, $transfer) extends \Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility { use OfferCommerceRoute; };
$bounded->fixtureOffers = $offers;
$selected = $offers->selection('com', 'transfer', 1);
foreach ([null, new \Paymenter\Extensions\Servers\Domains\Services\CheckoutRefreshBudget] as $budget) {
    try { $bounded->observe('deadline-placeholder.com', $selected, [1, 2, 3], batch: $budget); }
    catch (RuntimeException $error) { same('Captured transfer deadline', $error->getMessage(), 'Only the synthetic deadline probe aborts'); }
    expect($deadline->value !== null, 'Transfer reaches the guarded registrar boundary');
    if ($budget === null) { same($now->modify('+25 seconds')->format(DATE_ATOM), $deadline->value->format(DATE_ATOM), 'Standalone transfer retains its 25-second deadline'); }
    else { expect($deadline->value <= $now->modify('+15 seconds'), 'Transfer deadline fits the remaining cart batch'); }
}
$nativeSuccessful = true;
echo "Transfer refresh deadline: ok\n";
