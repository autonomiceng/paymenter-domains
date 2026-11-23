<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/Support/departure-fixture.php';
$invoice=$issueDepartureRenewal();
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchProductMap,WatchProfile,WatchSource};
use Paymenter\Extensions\Servers\Domains\Services\{DepartureChecks,ObservationProjection,RegistrationDns,SiteDnsDefault};

$nativeSuccessful=false;
// The suspected holding's last registrar read lists our site nameservers, and its owner has no hosting service for it.
$site=['ns1.site-dns.example.net','ns2.site-dns.example.net'];
DB::transaction(function()use($d,$site):void{$fresh=$d->fresh();$p=$fresh->observation;if(isset($p['sections']['nameservers'])){$p['sections']['nameservers']['facts']['nameservers']=$site;}else{$p['nameservers']['nameservers']=$site;}$fresh->update(['observation'=>$p]);});
$fresh=$d->fresh();$read=ObservationProjection::nameservers($fresh);
same(true,DepartureChecks::suspected($fresh),'The holding is under departure review');
same(['observed',$site],[$read?->state,$read?->nameservers],'The last registrar read lists the site nameservers');
same(false,RegistrationDns::registrarDns($fresh),'The read nameservers are not the registrar DNS');
$profile=DB::transaction(fn()=>WatchProfile::create(['name'=>'Site DNS','nameservers'=>$site,
    'enabled'=>true,'edited_by'=>$user->id,'reason'=>'Synthetic site default']));
fixtureClearBusinessChoice(SiteDnsDefault::KEY);fixtureBusinessChoice(SiteDnsDefault::KEY,(string)$profile->id,'string');
// Without a hosting service nothing keeps the records working, so no page claims a hosting plan.
same('other',DepartureChecks::dnsCase($fresh),'Our nameservers without a hosting service are not the hosting case');
same(null,DepartureChecks::dnsNote($fresh),'The customer is told nothing about a hosting plan');
$nativeSuccessful=true;
