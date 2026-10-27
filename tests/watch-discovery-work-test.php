<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryWork;
extract(dnsFixture());$now=1800000000;$seen=[];$d=new WatchDiscoveryWork(function($id,$product)use(&$seen){expect(DB::transactionLevel()===0,'Producer outside scheduler locks');$seen[]=[$id,$product];});
$native=(array)$service->getRawOriginal();unset($native['id']);for($i=0;$i<110;$i++){DB::table('services')->insert($native);}
$second=(array)$product->getRawOriginal();unset($second['id']);$second['slug']='second-map';$pid=DB::table('products')->insertGetId($second);$native['product_id']=$pid;DB::table('services')->insert($native);
$m=(array)$map->getRawOriginal();unset($m['id']);$m['product_id']=$pid;$m['enabled']=true;DB::table('domains_watch_product_maps')->insert($m);
same(76,$d->tick($now),'Four pages total include other product turn');expect(in_array($pid,array_column($seen,1),true),'Small product not starved by large backlog');same(0,(new WatchDiscoveryWork())->tick($now),'Missing accepted producer holds discovery');
$seen=[];$d->hint((int)$product->id);expect($d->tick($now+300)<=100,'At most one hundred services per tick');expect(in_array((int)$service->id,array_column($seen,0),true),'Mid-pass hint revisits earlier service before daily wait');
DB::table('services')->where('id',$service->id)->delete();DB::table('domains_watch_discovery')->where('product_id',$product->id)->update(['cursor'=>0,'due_at'=>$now+600]);$seen=[];$d->hint((int)$product->id);$d->tick($now+600);expect(in_array((int)$service->id,array_column($seen,0),true),'Deleted source service reaches accepted retirement producer');
DB::table('domains_watch_discovery')->update(['cursor'=>0,'due_at'=>$now+900,'hint'=>false]);$calls=0;$bad=new WatchDiscoveryWork(function($id)use(&$calls){$calls++;throw new RuntimeException('Synthetic local failure');});expect($bad->tick($now+900)<=100,'Failing producer stays bounded');expect($calls>1&&$bad->failed,'One throwing service does not block other IDs');
DB::table('domains_watch_discovery')->update(['cursor'=>0,'due_at'=>$now+86400,'hint'=>false]);same(0,$d->tick($now+1000),'Completed pass revisits daily');$d->hint((int)$product->id);$d->hint((int)$product->id);expect($d->tick($now+1200)<=100,'Repeated hints coalesce');
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>false]);DB::table('domains_watch_discovery')->where('product_id',$product->id)->delete();$seen=[];$d->tick($now+1300);same([[(int)$service->id,(int)$product->id]],$seen,'Disabled mapping seeds retained cleanup only, without enrolling native services');
DB::statement('ALTER TABLE domains_watch_discovery RENAME TO unavailable_discovery');$d->hint((int)$product->id);DB::statement('ALTER TABLE unavailable_discovery RENAME TO domains_watch_discovery');
$nativeSuccessful=true;echo "Four bounded discovery pages, per-product fairness, durable cursors, deleted sources and preserved hints passed.\n";
