<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting, Capabilities, OfferScheduler, UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, CartAdmission, Installation, NativeCatalogue, ObservationWork, Runtime, RuntimePorts};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

// Real installed container seams are necessary: a caller-supplied adapter matrix cannot prove cutover.
(new Installation)->install(); extract(commerceFixture(true));
app()->instance(AccountBindings::class, $bindings); app()->instance(NativeCatalogue::class, $native);
app()->instance(Clock::class, new FakeClock($now)); Runtime::register();
same(true, is_callable([CallerRouting::class, 'contract']), 'Installed routing must expose its compiled construction-only contract');
$before = DB::table('domains_tlds')->orderBy('id')->get()->toJson(); $calls = $reads->calls;
$contract = CallerRouting::contract();
same(15, count($contract['routes']), 'Contract covers every installed caller family and initial-record producer');
expect(preg_match('/^[a-f0-9]{64}$/D', $contract['id']) === 1, 'Contract identifier is digest-only');
same(null, Capabilities::routed(), 'Construction probe cannot leak future capability routing');
same(null, OfferScheduler::routed(), 'Fresh installation keeps legacy scheduling');
same(app(ObservationWork::class), Runtime::observations(), 'Installed runtime still selects legacy before activation');
same($before, DB::table('domains_tlds')->orderBy('id')->get()->toJson(), 'Inspection writes no catalogue rows');
same($calls, $reads->calls, 'Inspection does not resolve a provider');
$admission = app(CartAdmission::class);
app()->instance(CartAdmission::class, new class($native, $sellers, app(Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility::class), app(Clock::class)) extends CartAdmission {
    protected function catalogue(): ?Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers { return null; }
});
commerceReject(fn () => CallerRouting::contract());
same(null, Capabilities::routed(), 'Failed inspection resets the future composition in finally');
app()->instance(CartAdmission::class, $admission);
same($contract, CallerRouting::contract(), 'Restored installed adapters reproduce the compiled contract');
$gate = new UpgradeGate; $gate->pause();
same($contract, CallerRouting::contract(), 'Paused inspection remains construction-only');
commerceReject(fn () => app(RuntimePorts::class)->registerInitialManagedRecordObservations(static fn () => null));
same($calls, $reads->calls, 'Paused inspection and duplicate callback refusal send nothing');
same(null, Capabilities::routed(), 'Checked activation remains the only route to the active composition');
$nativeSuccessful = true;
echo "Installed dormant routing contract and probe isolation passed.\n";
