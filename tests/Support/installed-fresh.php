<?php
// Fresh active-catalogue checkout data from installed ZIP classes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Cost,Edit,Facts,FactsObserved,FeedObserved,Intent,Revision,UpgradeGate};

function installedFreshCommerceFixture(): array
{
    same('active',UpgradeGate::routeState()['state'],'Fresh ZIP starts with the offer catalogue active');
    App\Models\Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
    App\Models\Category::create(['name'=>'Domains','slug'=>'domains','description'=>'Domains']);
    $registry=new Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
    $reads=(object)['calls'=>0];
    $registry->register('enom',['token'],new class implements Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier {
        public function verify(Paymenter\Extensions\Servers\Domains\Data\Environment $env,#[SensitiveParameter] array $credentials): string {return $credentials['token'];}
    },static function($binding,$credentials)use($reads){
        expect(Illuminate\Support\Facades\DB::transactionLevel()===0,'No provider reads under locks');$reads->calls++;
        return new Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar($binding,new Paymenter\Extensions\Servers\Domains\Data\Capabilities($binding,[]),
            new Paymenter\Extensions\Servers\Domains\Data\DriverResult($binding,Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted,
                Paymenter\Extensions\Servers\Domains\Data\SendState::Sent,Paymenter\Extensions\Servers\Domains\Data\ResultReason::Acknowledged,
                new Paymenter\Extensions\Servers\Domains\Data\Evidence(premium:false,available:true)));
    });
    $bindings=new Paymenter\Extensions\Servers\Domains\Services\AccountBindings($registry);
    $prices=new Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue(new Paymenter\Extensions\Servers\Domains\Services\PricingRules);
    $native=new Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue($prices);
    $sellers=new Paymenter\Extensions\Servers\Domains\Services\SellerPolicies($prices,$bindings);
    $now=new DateTimeImmutable('2026-10-18T12:00:00Z');
    app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now));
    foreach([Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class=>$bindings,Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::class=>$native,
        Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue::class=>$prices,Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class=>$sellers]as$type=>$instance){app()->instance($type,$instance);}
    // Active routing composes the installed scheduler as well as checkout readers.
    Paymenter\Extensions\Servers\Domains\Services\Runtime::register();
    $server=App\Models\Server::create(['name'=>'Synthetic active Enom','type'=>'server','extension'=>'Domains','enabled'=>true]);
    $binding=$bindings->configure($server->id,'enom',Paymenter\Extensions\Servers\Domains\Data\Environment::Test,['token'=>'account-enom']);
    $bindings->setProcessing($server->id,true,static fn()=>null);
    // Synthetic TEST deployment and protocol acceptance; the installed routing/feature guards stay real.
    config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true,'domains.enom_writes.renewal'=>true]);
    $server->settings()->create(['key'=>Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::KEY,'type'=>'string','encrypted'=>true,
        'value'=>json_encode(['fingerprint'=>$binding->fingerprint,...Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::protocol($binding)])]);
    $ports=CallerPorts::routed();expect($ports instanceof CallerPorts,'Fresh checkout uses installed active ports');
    $costs=[];
    foreach([['register','6.0000'],['renew','8.0000']]as[$action,$amount]){$costs[]=new Cost($action,1,true,$amount,Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,'synthetic',$now,$now->modify('+1 day'));}
    $revision=$ports->apply(new FeedObserved($binding,'com',$costs),new Revision(0,0,0));
    $revision=$ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[1],'renew'=>[1],'transfer'=>[]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$revision);
    $revision=$ports->apply(Edit::override($binding,'com',new Intent(sell:true)),$revision);
    $ports->apply(Edit::seller($binding,'com',['register']),$revision);
    $tlds=[Paymenter\Extensions\Servers\Domains\Models\Tld::where('server_id',$server->id)->sole()];
    return compact('bindings','prices','sellers','native','now','tlds','reads');
}
