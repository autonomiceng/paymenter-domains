<?php
// Normal installed adapters around independent synthetic account/offer evidence.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
function installedModuleRouting($bindings,$clock,$fixturePorts,$fixtureClock,$precheck): array
{
    $namespace='Paymenter\\Extensions\\Servers\\Domains\\Services\\';
    $prices=new \Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue(new \Paymenter\Extensions\Servers\Domains\Services\PricingRules);
    $native=new \Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue($prices);
    $sellers=new \Paymenter\Extensions\Servers\Domains\Services\SellerPolicies($prices,$bindings);
    activeFixturePorts($sellers,$fixturePorts,$fixtureClock);
    foreach(['AccountBindings'=>$bindings,'PriceCatalogue'=>$prices,'NativeCatalogue'=>$native,'SellerPolicies'=>$sellers,'TransferPrecheck'=>$precheck] as $type=>$value){app()->instance($namespace.$type,$value);}
    config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,
        'domains.enom_writes.registration'=>true,'domains.enom_writes.renewal'=>true,'domains.enom_writes.transfer'=>true]);
    // Explicit local fixture premise; never inferred from the read reply or staff sales intent.
    foreach(\Paymenter\Extensions\Servers\Domains\Models\Account::where('registrar_key','enom')->get() as $account){
        $binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account);
        foreach(['Renewal','Transfer'] as $action){$type=$namespace.'EnomTest'.$action;
            \App\Models\Server::findOrFail($binding->serverId)->settings()->updateOrCreate(['key'=>$type::KEY],
                ['type'=>'string','encrypted'=>true,'value'=>json_encode(['fingerprint'=>$binding->fingerprint,...$type::protocol($binding)])]);
        }
    }
    installedFixtureRenewalReviews();
    foreach(['RuntimePorts','RuntimeWork','CheckoutEligibility','CartAdmission','InvoiceBinding','HolderPricing','OwnedDomains','NativePresenter'] as $type){app()->forgetInstance($namespace.$type);}
    \Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::reset();
    (new \Paymenter\Extensions\Servers\Domains\Domains)->boot();
    return [$prices,$native,$sellers];
}

/** Synthetic financial ports retain the real installed scheduler producer. */
function installedRuntimePorts(): \Paymenter\Extensions\Servers\Domains\Services\RuntimePorts
{
    $ports=new \Paymenter\Extensions\Servers\Domains\Services\RuntimePorts;
    $ports->registerInitialManagedRecordObservations(\Paymenter\Extensions\Servers\Domains\Services\Runtime::initialManagedRecordObservations(...));
    return $ports;
}

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
