<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline commerce composition shared by native MariaDB processes.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CartAdmission,HolderPricing,InvoiceBinding,NativeCatalogue,NativeDomainGuard,NativeIdentity,PriceCatalogue,PricingRules,RegistrarRegistry,SellerPolicies};
use Paymenter\Extensions\Servers\Domains\Tests\{FakeClock,FakeRegistrar};
$clock=new FakeClock(new DateTimeImmutable('2026-10-18T12:00:00Z'));
$registry=new RegistrarRegistry;
$verifier=new class implements \Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier {
    public function verify(\Paymenter\Extensions\Servers\Domains\Data\Environment $env,#[SensitiveParameter]array $credentials):string{return $credentials['token'];}
};
foreach(['enom','namesilo']as$key){$registry->register($key,['token'],$verifier,static function($b,$c):FakeRegistrar {
    same(0,DB::transactionLevel(),'Synthetic availability outside locks');
    return new FakeRegistrar($b,new \Paymenter\Extensions\Servers\Domains\Data\Capabilities($b,[]),
        new \Paymenter\Extensions\Servers\Domains\Data\DriverResult($b,\Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted,
            \Paymenter\Extensions\Servers\Domains\Data\SendState::Sent,\Paymenter\Extensions\Servers\Domains\Data\ResultReason::Acknowledged,
            new \Paymenter\Extensions\Servers\Domains\Data\Evidence(premium:false,available:true)));
});}
$bindings=new AccountBindings($registry);$prices=new PriceCatalogue(new PricingRules);$native=new NativeCatalogue($prices);$sellers=new SellerPolicies($prices,$bindings);
foreach([RegistrarRegistry::class=>$registry,AccountBindings::class=>$bindings,PriceCatalogue::class=>$prices,NativeCatalogue::class=>$native,SellerPolicies::class=>$sellers,\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class=>$clock]as$class=>$instance){app()->instance($class,$instance);}
// Each native process composes the same offline offer reader the seed fixture registers.
$ports=new \Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts(new \Paymenter\Extensions\Servers\Domains\Catalogue\Projection($native),$clock,$bindings,static fn()=>null,static fn()=>true);
$offers=new \Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers($ports,$bindings,$native);
foreach([$prices,$sellers,$native,$bindings]as$owner){activeFixturePorts($owner,$ports,$clock);activeFixtureOffers($owner,$offers);}
$admission=activeFixtureAdmission($native,$sellers,activeFixtureEligibility($bindings,$clock),$clock);$admission->boot();app()->instance(CartAdmission::class,$admission);
$binder=new InvoiceBinding($admission,$native,$prices,$clock);app()->instance(InvoiceBinding::class,$binder);$binder->boot();
NativeIdentity::boot();(new NativeDomainGuard(new HolderPricing($prices),$clock))->boot();
// Rebuild the same synthetic credential contract in each native process.
foreach(\Paymenter\Extensions\Servers\Domains\Models\Account::all()as$account){
    $server=\App\Models\Server::findOrFail($account->server_id);
    $credentials=json_decode($server->settings()->where('key','domains_credentials')->sole()->value,true,8,JSON_THROW_ON_ERROR);
    $clean=$registry->credentials($account->registrar_key,$credentials);
    $binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account);
    same(hash_hmac('sha256',$binding->fingerprint.json_encode($clean,JSON_THROW_ON_ERROR),(string)config('app.key')),$server->settings()->where('key','domains_verified_credentials')->sole()->value,'Synthetic credential contract survives native process boundary');
}
