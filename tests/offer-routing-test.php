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
(new Installation)->install(); extract(commerceFixture(true,active:true));
app()->instance(AccountBindings::class, $bindings); app()->instance(NativeCatalogue::class, $native);
app()->instance(Clock::class, new FakeClock($now)); Runtime::register();
same(true, is_callable([CallerRouting::class, 'contract']), 'Installed routing must expose its compiled construction-only contract');
$before = DB::table('domains_tlds')->orderBy('id')->get()->toJson(); $calls = $reads->calls;
$contract = CallerRouting::contract();
same(15, count($contract['routes']), 'Contract covers every installed caller family and initial-record producer');
expect(preg_match('/^[a-f0-9]{64}$/D', $contract['id']) === 1, 'Contract identifier is digest-only');
expect(Capabilities::routed() instanceof Capabilities,'Actual fresh lifecycle retains active capabilities outside construction probe');
expect(OfferScheduler::routed() instanceof OfferScheduler,'Fresh lifecycle selects shared scheduler');
same(OfferScheduler::routed(),Runtime::observations(),'Installed runtime shares the active scheduler');
same($before, DB::table('domains_tlds')->orderBy('id')->get()->toJson(), 'Inspection writes no catalogue rows');
same($calls, $reads->calls, 'Inspection does not resolve a provider');
$admission = app(CartAdmission::class);
app()->instance(CartAdmission::class, new class($native, $sellers, app(Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility::class), app(Clock::class)) extends CartAdmission {
    protected function catalogue(): ?Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers { return null; }
});
commerceReject(fn () => CallerRouting::contract());
expect(Capabilities::routed() instanceof Capabilities,'Failed inspection cannot erase actual lifecycle authority');
app()->instance(CartAdmission::class, $admission);
same($contract, CallerRouting::contract(), 'Restored installed adapters reproduce the compiled contract');
commerceReject(fn()=>(new UpgradeGate)->pause(),'Active final package cannot reopen bridge conversion');
same($contract, CallerRouting::contract(), 'Paused inspection remains construction-only');
commerceReject(fn () => app(RuntimePorts::class)->registerInitialManagedRecordObservations(static fn () => null));
same($calls, $reads->calls, 'Paused inspection and duplicate callback refusal send nothing');
expect(Capabilities::routed() instanceof Capabilities,'Refused legacy transition preserves actual active composition');
$nativeSuccessful = true;
echo "Installed dormant routing contract and probe isolation passed.\n";
