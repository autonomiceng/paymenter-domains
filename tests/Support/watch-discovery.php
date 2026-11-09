<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/watch-dns.php';
require_once __DIR__.'/psl.php';
require_once __DIR__.'/FakeDnsReader.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,DnsReader};
use Paymenter\Extensions\Servers\Domains\Services\{PublicSuffixList,PslTransport,PslValidator,WatchDiscoveryWork};
function discoveryFixture(): array
{
    config(['queue.default'=>'database','queue.connections.database'=>['driver'=>'database','retry_after'=>180]]);
    $clock=new PslClock;$v=new PslValidator(128,5,1);
    app()->instance(Clock::class,$clock);app()->instance(PslValidator::class,$v);
    $psl=new PublicSuffixList($clock,new PslTransport(fn()=>['status'=>200,'body'=>str_replace('p1.example','hosting.example.com',pslFixture())]),$v);
    app()->instance(PublicSuffixList::class,$psl);
    $dns=new Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader;app()->instance(DnsReader::class,$dns);
    $f=dnsFixture();same('updated',$psl->update(),'Synthetic current suffix list');
    // Start with native hosting only, rather than the manually enrolled DNS fixture.
    DB::table('domains_watch_sources')->delete();DB::table('domains_watch_names')->delete();
    $f['entry']->boot();
    same(false,app(WatchDiscoveryWork::class)->ready(),'Fresh installation does not activate discovery');
    same(false,app(Paymenter\Extensions\Servers\Domains\Services\WatchWork::class)->active(),'Fresh installation does not activate public reads');
    // Compose the accepted local producer through the real scheduler, with synthetic public ports only.
    $work=new WatchDiscoveryWork(fn($id,$product)=>app(Paymenter\Extensions\Servers\Domains\Services\WatchDiscovery::class)->discover($id,$product));
    app()->instance(WatchDiscoveryWork::class,$work);
    app()->instance(Paymenter\Extensions\Servers\Domains\Services\WatchWork::class,new Paymenter\Extensions\Servers\Domains\Services\WatchWork($dns,$work,true,fn()=>$clock->at,static fn()=>null));
    $sweep=static function()use($work,$clock,$f):void {$work->hint((int)$f['product']->id);$work->tick($clock->at);expect(!$work->failed,'Discovery completes without swallowed failures');};
    return [...$f,...compact('clock','psl','dns','work','sweep')];
}
function discoveryService(array $f,string $value,?int $owner=null): App\Models\Service
{
    $o=App\Models\Order::create(['user_id'=>$owner??$f['owner']->id,'currency_code'=>'USD']);
    $s=App\Models\Service::create(['user_id'=>$o->user_id,'order_id'=>$o->id,'product_id'=>$f['product']->id,'plan_id'=>$f['plan']->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
    $s->properties()->create(['key'=>'domain','value'=>$value]);return $s;
}
