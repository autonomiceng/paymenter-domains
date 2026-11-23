<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/../native-app.php';
require_once __DIR__.'/staff-panel.php';
use App\Models\{Category,Currency,Product,Server,Service,Order};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\DnsTemplate;
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
    $profile = DB::transaction(fn()=>DnsTemplate::create(['name'=>'Synthetic defaults','nameservers'=>['NS2.platform.test.','ns1.platform.test'],
        'edited_by'=>$owner->id,'reason'=>'Synthetic composition']));
    return compact('entry','owner','other','server','product','plan','order','service','property','profile');
}
