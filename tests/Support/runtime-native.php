<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require dirname(__DIR__).'/native-app.php';require __DIR__.'/commerce.php';require __DIR__.'/native-commerce.php';require __DIR__.'/installed.php';require_once __DIR__.'/staff-panel.php';
use App\Models\{Invoice,Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Services\{Runtime,RuntimePorts,AccountBindings,CartAdmission,CheckoutEligibility,InvoiceBinding,NativeCatalogue,PriceCatalogue,OwnedDomains,NativePresenter,OwnerPorts,HolderPricing};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
// Real fresh installed routing; synthetic registry evidence stays independent of native ownership/finances.
(new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
$entry=new Domains;$entry->installed();extract(commerceFixture(true,static fn()=>null));
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);app()->instance(AccountBindings::class,$bindings);app()->instance(NativeCatalogue::class,$native);app()->instance(PriceCatalogue::class,$prices);
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class,$sellers);
// Use the normal installed adapters rather than binding module-only private ports into the container.
$prices=new PriceCatalogue(new \Paymenter\Extensions\Servers\Domains\Services\PricingRules);$native=new NativeCatalogue($prices);$sellers=new \Paymenter\Extensions\Servers\Domains\Services\SellerPolicies($prices,$bindings);activeFixturePorts($sellers,$ports,$clock);
foreach([PriceCatalogue::class=>$prices,NativeCatalogue::class=>$native,\Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class=>$sellers] as $type=>$value){app()->instance($type,$value);}
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true,'domains.enom_writes.renewal'=>true]);
$enom=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$tlds[0]->server_id)->sole());
\App\Models\Server::find($enom->serverId)->settings()->updateOrCreate(['key'=>\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::KEY],['type'=>'string','encrypted'=>true,'value'=>json_encode(['fingerprint'=>$enom->fingerprint,...\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::protocol($enom)])]);
/** Checkout includes annual renewal pricing, so its installed fixture reviews that switch explicitly. */
function installedFixtureRenewalReviews(): void
{
    // A one-registrar wire composition does not enroll its unused synthetic neighbour.
    if(!app(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class)->hasRegistrar('namesilo')){return;}
    foreach(\Paymenter\Extensions\Servers\Domains\Models\Account::where('registrar_key','namesilo')->get() as $account){
        $binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account);
        $actor=\App\Models\User::where('email','fixture-enrollment@example.test')->sole();
        $type=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::class;
        $review=new $type($actor->id,$binding->serverId,null,'test_renewal',$type::ticket($actor->id,$binding->serverId,null,'test_renewal',$type::stamp($binding->serverId,null)),'Synthetic installed annual renewal review');
        \Paymenter\Extensions\Servers\Domains\Services\NameSiloFeatureSettings::review($review,['accept'=>true,'verified'=>true],'renewal');
        expect(\Paymenter\Extensions\Servers\Domains\Services\NameSiloFeatureSettings::accepted($binding,'renewal'),'Installed synthetic annual renewal acceptance is explicit');
    }
}

installedFixtureRenewalReviews();
app()->offsetUnset(RuntimePorts::class);\Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::reset();
// FreshInstallation inspects these adapters before the module fixture replaces its bindings.
foreach([\Paymenter\Extensions\Servers\Domains\Services\RuntimeWork::class,CheckoutEligibility::class,CartAdmission::class,InvoiceBinding::class,HolderPricing::class,OwnedDomains::class,NativePresenter::class] as $type){app()->forgetInstance($type);}
$entry->boot();installedPanel();
