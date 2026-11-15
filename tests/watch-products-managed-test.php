<?php
// A hosting service whose domain Domains already manages for the same owner links to it instead of starting a second watch, whichever came first. Products whose orders carry a domain and a hostname are now in scope, so they reach this. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-discovery.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,WatchName,WatchSource};
use Paymenter\Extensions\Servers\Domains\Services\WatchProducts;
$f=discoveryFixture();extract($f);
$registrar=App\Models\Server::create(['name'=>'Synthetic registrar','extension'=>'Domains','type'=>'server','enabled'=>true]);
$account=Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$registrar->id,'registrar_key'=>'enom','environment'=>'test','stable_identity'=>'synthetic-products-binding']));
$managed=function(string $name)use($owner,$product,$plan,$registrar,$account){
    $o=App\Models\Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
    $s=App\Models\Service::create(['user_id'=>$owner->id,'order_id'=>$o->id,'product_id'=>$product->id,'plan_id'=>$plan->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
    [$sld,$tld]=explode('.',$name,2);
    return Domain::create(['server_id'=>$registrar->id,'registrar_key'=>'enom','account_fingerprint'=>$account->account_fingerprint,'domain'=>$name,'sld'=>$sld,'tld'=>$tld,'user_id'=>$owner->id,'service_id'=>$s->id,'status'=>'active']);
};
// A Plesk-style product: each order saves the site's domain and the server's hostname.
$plesk=$product->replicate();$plesk->name='Plesk hosting';$plesk->slug='plesk-hosting';$plesk->save();
$order=function(string $domain)use($owner,$plesk,$plan){
    $o=App\Models\Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
    $s=App\Models\Service::create(['user_id'=>$owner->id,'order_id'=>$o->id,'product_id'=>$plesk->id,'plan_id'=>$plan->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
    $s->properties()->create(['key'=>'domain','value'=>$domain]);$s->properties()->create(['key'=>'hostname','value'=>'box1.hosting.example.net']);return $s;
};
$sweep=function()use($work,$clock,$plesk){WatchProducts::adopt($plesk->fresh());$work->hint((int)$plesk->id);$work->tick($clock->at);expect(!$work->failed,'Discovery completes');};
// The domain came first: the hosting order links to it.
$managed('managed.com');$first=$order('www.managed.com');$sweep();
same('managed_link',WatchSource::where('service_id',$first->id)->value('state'),'Hosting for a domain Domains manages links to it');
same(0,WatchName::where('canonical_name','managed.com')->count(),'No second watch for a managed domain');
// The hosting came first: its watch links once Domains manages the domain.
$later=$order('later.com');$sweep();same('watch',WatchSource::where('service_id',$later->id)->value('state'),'Hosting for an outside domain is watched');
$domain=$managed('later.com');$sweep();
same(['managed_link',(int)$domain->id],[WatchSource::where('service_id',$later->id)->value('state'),(int)WatchSource::where('service_id',$later->id)->value('managed_domain_id')],'The watch moves to the managed domain once Domains manages it');
same([1,'linked'],[WatchName::where('canonical_name','later.com')->count(),WatchName::where('canonical_name','later.com')->value('state')],'The earlier watch is linked, not duplicated or left checking');
// Mixed orders on one product: each service reads its own domain, else its hostname. A later domain order, or an old
// cancelled service with a domain, never moves the hostname-only services off their monitored domains.
$mixed=$product->replicate();$mixed->name='Mixed hosting';$mixed->slug='mixed-hosting';$mixed->save();
$sell=function(array $values,string $status='active')use($owner,$mixed,$plan){
    $o=App\Models\Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
    $s=App\Models\Service::create(['user_id'=>$owner->id,'order_id'=>$o->id,'product_id'=>$mixed->id,'plan_id'=>$plan->id,'status'=>$status,'quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
    foreach($values as $key=>$value){$s->properties()->create(['key'=>$key,'value'=>$value]);}return $s;
};
$visit=function()use($work,$clock,$mixed){WatchProducts::adopt($mixed->fresh());$work->hint((int)$mixed->id);$work->tick($clock->at);expect(!$work->failed,'Discovery completes');};
$hostOnly=$sell(['hostname'=>'hostonly.com']);$visit();same('watch',WatchSource::where('service_id',$hostOnly->id)->value('state'),'A hostname-only order is watched');
$sell(['domain'=>'cancelled.com'],'cancelled');$withDomain=$sell(['domain'=>'www.ordered.com','hostname'=>'box1.hosting.example.net']);$visit();$visit();
same(['watch','active'],[WatchSource::where('service_id',$hostOnly->id)->value('state'),WatchName::where('canonical_name','hostonly.com')->value('state')],'The hostname-only order stays monitored after domain orders join the product');
same(['watch','ordered.com'],[WatchSource::where('service_id',$withDomain->id)->value('state'),WatchSource::where('service_id',$withDomain->id)->value('registrable_name')],'The order with a domain is watched by its domain, not its hostname');
// Public checks read the same field as discovery, whichever key the product's map holds.
DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['enabled'=>true]);
$mixedMap=fn()=>Paymenter\Extensions\Servers\Domains\Models\WatchProductMap::where('product_id',$mixed->id)->sole();$key=fn(string $k)=>DB::table('domains_watch_product_maps')->where('product_id',$mixed->id)->update(['property_key'=>$k]);
$registry=new Paymenter\Extensions\Servers\Domains\Services\WatchRegistryChecks(true);$checks=new Paymenter\Extensions\Servers\Domains\Services\WatchDnsChecks(true);
$monitored=function(string $name)use($registry,$checks,$owner,$clock,$profile){
    $id=(int)WatchName::where('canonical_name',$name)->value('id');DB::table('domains_watch_names')->where('id',$id)->update(['ns_profile_id'=>$profile->id,'profile_revision'=>$profile->fresh()->revision]);
    return [$registry->enroll($id,(int)$owner->id,$clock->at)&&$registry->begin($id,(int)$owner->id,$clock->at)!==null,$checks->enroll($id,(int)$owner->id,$clock->at)&&$checks->begin($id,(int)$owner->id,'delegation',$clock->at)!==null];
};
$key('domain');same([true,true],$monitored('hostonly.com'),'A hostname-only order on a domain map gets expiry and DNS checks');
$key('hostname');same([true,true],$monitored('ordered.com'),'An order with a domain on a hostname map gets expiry and DNS checks');
// Managed first: a mixed order linking to a managed domain still proves its hosting product's DNS template.
$mixedManaged=$managed('mixedmanaged.com');$linked=$sell(['domain'=>'www.mixedmanaged.com','hostname'=>'box2.hosting.example.net']);$visit();$key('hostname');
same('managed_link',WatchSource::where('service_id',$linked->id)->value('state'),'A mixed order for a managed domain links to it');
DB::table('domains_watch_product_maps')->where('product_id',$mixed->id)->update(['expected_ns_profile_id'=>$profile->id]);
$locks=['domain'=>$mixedManaged->fresh(),'services'=>collect([$linked->fresh()]),'orders'=>collect([App\Models\Order::find($linked->order_id)]),'products'=>collect([$mixed->fresh()]),'relatedProperties'=>$linked->properties()->get()];
$proof=DB::transaction(fn()=>Paymenter\Extensions\Servers\Domains\Services\ManagedDnsSetup::proven($locks,null,null,WatchSource::where('service_id',$linked->id)->get(),[$mixedMap()->id=>$mixedMap()]));
same([(int)$profile->id,'platform','hosting_product'],[(int)$proof[0]?->id,$proof[1],$proof[2]],'The managed domain inherits the hosting product template from a mixed order');
$nativeSuccessful=true;echo "Hosting links to managed domains.\n";
