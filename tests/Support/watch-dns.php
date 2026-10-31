<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/../native-app.php';
require_once __DIR__.'/staff-panel.php';
use App\Models\{Category,Currency,Product,Server,Service,Order};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchProfile,WatchName,WatchSource,WatchProductMap};
function dnsFixture(): array
{
    $entry = new Paymenter\Extensions\Servers\Domains\Domains([]); $entry->installed();
    $owner = staffUser([]); $other = staffUser([]);
    Currency::firstOrCreate(['code'=>'USD'], ['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
    $category = Category::create(['name'=>'Synthetic hosting','slug'=>'dns-hosting']);
    $server = Server::create(['name'=>'Synthetic hosting','extension'=>'SyntheticHosting','type'=>'server','enabled'=>true]);
    $product = Product::create(['name'=>'Hosting','slug'=>'dns-hosting','category_id'=>$category->id,'server_id'=>$server->id,'hidden'=>true,'allow_quantity'=>'combined']);
    $plan = $product->plans()->create(['name'=>'Monthly','type'=>'recurring','billing_period'=>1,'billing_unit'=>'month']);
    $order = Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
    $service = Service::create(['user_id'=>$owner->id,'order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$plan->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
    $property = $service->properties()->create(['key'=>'domain','name'=>'Domain','value'=>'www.example.com']);
    $profile = DB::transaction(fn()=>WatchProfile::create(['name'=>'Synthetic defaults','nameservers'=>['NS2.platform.test.','ns1.platform.test'],
        'website_endpoints'=>['ips'=>['192.0.2.1']], 'email_endpoints'=>['exact'=>['mx.platform.test']], 'edited_by'=>$owner->id,'reason'=>'Synthetic composition']));
    $map = DB::transaction(fn()=>WatchProductMap::create(['product_id'=>$product->id,'property_key'=>'domain','edited_by'=>$owner->id,'reason'=>'Synthetic map']));
    // W1 activation action is deliberately absent. Privileged fixtures compose future discovery only.
    DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>true]);
    $watch = DB::transaction(fn()=>WatchName::create(['user_id'=>$owner->id,'canonical_name'=>'example.com','psl_revision'=>str_repeat('a',64),
        'state'=>'active','expected_dns'=>'platform','ns_profile_id'=>$profile->id,'profile_revision'=>1,'notice_preferences'=>[]]));
    $source = DB::transaction(fn()=>WatchSource::create(['user_id'=>$owner->id,'service_id'=>$service->id,'map_id'=>$map->id,'map_revision'=>1,
        'hostname'=>'www.example.com','registrable_name'=>'example.com','watch_id'=>$watch->id,'state'=>'watch','source_digest'=>str_repeat('b',64),'last_seen_at'=>1800000000]));
    return compact('entry','owner','other','server','product','plan','order','service','property','profile','map','watch','source');
}
function dnsRefused(Closure $run, string $label, bool $transaction = true): void
{
    $refused = false;
    try { $transaction ? DB::transaction($run) : $run(); } catch (LogicException|RuntimeException) { $refused = true; }
    expect($refused, $label);
}
