<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use App\Models\{User, Invoice};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting, UpgradeGate, OfferScheduler, Capabilities, CallerPorts};
use Paymenter\Extensions\Servers\Domains\Services\{Runtime};

// A new Domains install may share Paymenter with unrelated customers and financial history.
$user = User::create(['first_name'=>'Synthetic','last_name'=>'Owner','email'=>'fresh-owner@example.com','password'=>'test-password']);
$invoice = Invoice::create(['user_id'=>$user->id,'currency_code'=>'USD','status'=>'paid']);
$before = serialize([$user->fresh()->getAttributes(), $invoice->fresh()->getAttributes()]);
$entry = new Domains;
Runtime::register(); $uninstalledRefused = false;
try { Runtime::observations(); } catch (DomainException) { $uninstalledRefused = true; }
expect($uninstalledRefused, 'A completely uninstalled database refuses executable routing before the intent exists');
$entry->installed();
same('active', UpgradeGate::routeState()['state'], 'Proven-new installed extension selects active catalogue immediately');
same(2, UpgradeGate::generation(), 'Fresh intent and activation each own a generation');
expect(Capabilities::routed() instanceof Capabilities, 'Installed capability port is active');
expect(OfferScheduler::routed() instanceof OfferScheduler, 'Installed scheduler is active');
same(OfferScheduler::routed(), Runtime::observations(), 'Runtime uses the active holding worker');
expect(CallerRouting::resolve(CallerPorts::class) instanceof CallerPorts, 'Installed checkout/runtime port is active');
same(0, DB::table('domains_accounts')->count(), 'Initialization creates no account or enabled processing');
same(0, DB::table('domains_tlds')->count(), 'Initialization creates no catalogue');
same(0, DB::table('domains_operations')->count(), 'Initialization sends no operation');
same($before, serialize([$user->fresh()->getAttributes(), $invoice->fresh()->getAttributes()]), 'Unrelated ownership and money are unchanged');
$receipt = UpgradeGate::routeState();
$entry->installed(); $entry->upgraded('0.1.0');
same($receipt, UpgradeGate::routeState(), 'Repeat lifecycle calls preserve the committed receipt');
try { (new UpgradeGate)->activate([]); throw new LogicException('Unauthenticated activation unexpectedly succeeded'); }
catch (DomainException $e) { expect(str_contains($e->getMessage(), 'Authenticated'), 'Fresh initializer does not weaken maintenance authentication'); }
$nativeSuccessful = true;
