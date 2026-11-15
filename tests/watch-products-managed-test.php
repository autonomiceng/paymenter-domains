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
$nativeSuccessful=true;echo "Hosting links to managed domains.\n";
