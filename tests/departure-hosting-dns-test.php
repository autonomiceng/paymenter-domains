<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/transfer-out-not-found-test.php';
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
$profile=DB::transaction(fn()=>WatchProfile::create(['name'=>'Site DNS','nameservers'=>$site,'website_endpoints'=>[],'email_endpoints'=>[],
    'enabled'=>true,'edited_by'=>$user->id,'reason'=>'Synthetic site default']));
fixtureClearBusinessChoice(SiteDnsDefault::KEY);fixtureBusinessChoice(SiteDnsDefault::KEY,(string)$profile->id,'string');
// Without a hosting service nothing keeps the records working, so no page claims a hosting plan.
same('other',DepartureChecks::dnsCase($fresh),'Our nameservers without a hosting service are not the hosting case');
same(null,DepartureChecks::dnsNote($fresh),'The customer is told nothing about a hosting plan');
same(false,DepartureChecks::hosted($fresh),'The owner has no hosting service for this domain');
same('other',DepartureChecks::savedDnsCase($fresh,'hosting'),'A saved hosting case without a hosting service reads as other nameservers');

// The owner's active hosting names this domain: the hosting case applies.
$owner=(int)$d->user_id;$name=(string)$d->domain;
$product=App\Models\Product::create(['name'=>'Hosting','slug'=>'departure-dns-hosting','category_id'=>App\Models\Category::firstOrFail()->id,'allow_quantity'=>'combined','stock'=>10]);
$plan=$product->plans()->create(['name'=>'Monthly','type'=>'recurring','billing_period'=>1,'billing_unit'=>'month']);
$order=App\Models\Order::withoutEvents(fn()=>App\Models\Order::create(['user_id'=>$owner,'currency_code'=>'USD']));
$hosting=App\Models\Service::withoutEvents(fn()=>App\Models\Service::create(['user_id'=>$owner,'order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$plan->id,
    'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']));
$hosting->properties()->create(['key'=>'domain','value'=>'www.'.$name]);
$map=DB::transaction(fn()=>WatchProductMap::create(['product_id'=>$product->id,'property_key'=>'domain','edited_by'=>$owner,'reason'=>'Synthetic map']));
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>true]);
DB::transaction(fn()=>WatchSource::create(['user_id'=>$owner,'service_id'=>$hosting->id,'map_id'=>$map->id,'map_revision'=>1,'hostname'=>'www.'.$name,
    'registrable_name'=>$name,'managed_domain_id'=>$d->id,'state'=>'managed_link','source_digest'=>str_repeat('b',64),'last_seen_at'=>1]));
same(true,DepartureChecks::hosted($d->fresh()),'The owner now has a hosting service for this domain');
same('hosting',DepartureChecks::dnsCase($d->fresh()),'Our nameservers with a hosting service are the hosting case');
same('hosting',DepartureChecks::savedDnsCase($d->fresh(),'hosting'),'A saved hosting case keeps reading as hosting while the service is active');
// A suspended hosting service no longer keeps the records working.
$hosting->update(['status'=>'suspended']);
same('other',DepartureChecks::dnsCase($d->fresh()),'A suspended hosting service is not the hosting case');
echo "The hosting DNS case applies only while the owner has an active hosting service for the domain.\n";
$nativeSuccessful=true;
