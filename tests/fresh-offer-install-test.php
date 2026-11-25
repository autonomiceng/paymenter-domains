<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use App\Models\{User, Invoice};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting, CatalogueActivation, OfferScheduler, Capabilities, CallerPorts};
use Paymenter\Extensions\Servers\Domains\Services\{Runtime};

// A new Domains install may share Paymenter with unrelated customers and financial history.
$user = User::create(['first_name'=>'Synthetic','last_name'=>'Owner','email'=>'fresh-owner@example.com','password'=>'test-password']);
$invoice = Invoice::create(['user_id'=>$user->id,'currency_code'=>'USD','status'=>'paid']);
$categoryId = DB::table('categories')->insertGetId(['name'=>'Synthetic hosting','slug'=>'synthetic-hosting']);
$productId = DB::table('products')->insertGetId(['category_id'=>$categoryId,'name'=>'Unrelated hosting','slug'=>'hosting','stock'=>5]);
$before = serialize([$user->fresh()->getAttributes(), $invoice->fresh()->getAttributes(), (array) DB::table('products')->where('id',$productId)->sole()]);
$payload = json_encode(['displayName'=>'App\\Jobs\\SyntheticHosting']);
$job = DB::table('jobs')->insertGetId(['queue'=>'default','payload'=>$payload,'attempts'=>0,'available_at'=>1,'created_at'=>1]);
$entry = new Domains;
Runtime::register(); $uninstalledRefused = false;
try { Runtime::observations(); } catch (DomainException) { $uninstalledRefused = true; }
expect($uninstalledRefused, 'A completely uninstalled database refuses executable routing before the intent exists');
$entry->installed();
same('active', CatalogueActivation::routeState()['state'], 'Proven-new installed extension selects active catalogue immediately');
same(2, CatalogueActivation::generation(), 'Fresh intent and activation each own a generation');
expect(Capabilities::routed() instanceof Capabilities, 'Installed capability port is active');
expect(OfferScheduler::routed() instanceof OfferScheduler, 'Installed scheduler is active');
same(OfferScheduler::routed(), Runtime::observations(), 'Runtime uses the active holding worker');
expect(CallerRouting::resolve(CallerPorts::class) instanceof CallerPorts, 'Installed checkout/runtime port is active');
same(0, DB::table('domains_accounts')->count(), 'Initialization creates no account or enabled processing');
same(0, DB::table('domains_tlds')->count(), 'Initialization creates no catalogue');
same(0, DB::table('domains_operations')->count(), 'Initialization sends no operation');
same($before, serialize([$user->fresh()->getAttributes(), $invoice->fresh()->getAttributes(), (array) DB::table('products')->where('id',$productId)->sole()]), 'Unrelated ownership, money and hosting stock are unchanged');
same($payload, DB::table('jobs')->where('id',$job)->value('payload'), 'Unrelated hosting work is unchanged');
$receipt = CatalogueActivation::routeState();
$entry->installed(); $entry->upgraded('0.1.0');
same($receipt, CatalogueActivation::routeState(), 'Repeat lifecycle calls preserve the committed receipt');
$unowned = (new ReflectionClass(\Paymenter\Extensions\Servers\Domains\Installation\Installer::class))->newInstanceWithoutConstructor();
$refused = false;
try { CatalogueActivation::initialize($unowned); } catch (LogicException) { $refused = true; }
expect($refused, 'Activation requires the initializer that owns the durable intent');
same($receipt, CatalogueActivation::routeState(), 'Unowned activation preserves the receipt');
$nativeSuccessful = true;
