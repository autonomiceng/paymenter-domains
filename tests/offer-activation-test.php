<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/retained-offer.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier,Clock};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Capabilities,Disposition,DriverResult,Environment,Evidence,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,NativeCatalogue,PriceCatalogue,PricingRules,RegistrarRegistry,Runtime,SellerPolicies};
use Paymenter\Extensions\Servers\Domains\Tests\{FakeClock,FakeRegistrar};
$saved=retainedOfferDatabase();$before=retainedOfferObligations();
$registry=new RegistrarRegistry;$reads=(object)['calls'=>0,'premium'=>false,'available'=>true];
$verifier=new class implements AccountVerifier {
    public function verify(Environment $env,array $credentials): string{return $credentials['token'];}
};
foreach(['enom','namesilo'] as $key){$registry->register($key,['token'],$verifier,static function(AccountBinding $b,array $c)use($reads):FakeRegistrar {
    same(0,Illuminate\Support\Facades\DB::transactionLevel(),'Provider reads stay outside locks');$reads->calls++;
    return new FakeRegistrar($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,new Evidence(premium:$reads->premium,available:$reads->available)));
});}
$bindings=new AccountBindings($registry);$prices=new PriceCatalogue(new PricingRules);$native=new NativeCatalogue($prices);
$sellers=new SellerPolicies($prices,$bindings);$now=new DateTimeImmutable('2026-10-18T12:00:00Z');$tlds=Tld::orderBy('id')->get()->all();
app()->instance(AccountBindings::class,$bindings);app()->instance(NativeCatalogue::class,$native);app()->instance(Clock::class,new FakeClock($now));
Runtime::register();$staff=App\Models\User::where('email','cutover@example.test')->sole();auth()->login($staff);
(new Paymenter\Extensions\Servers\Domains\Domains)->upgraded('0.1.0');
same($saved['receipt'],UpgradeGate::routeState(),'Current package retains the bridge checked receipt');
same($before,retainedOfferObligations(),'Current upgrade preserves bridge identities and frozen obligations');
same(0,$reads->calls,'Current upgrade creates no provider reads');
$nativeSuccessful=true;
