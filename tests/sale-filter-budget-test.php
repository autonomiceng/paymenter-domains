<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SellTable,Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;
extract(commerceFixture(true,registrars:['enom']));staffSalesFixture();tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);$t=$tlds[0];
$b=AccountBindings::binding(Account::where('server_id',$t->server_id)->sole());$source=new StaffCatalogue($ports,$clock);
$base=$t->fresh()->getRawOriginal();unset($base['id']);$prices=DB::table('domains_prices')->where('server_id',$b->serverId)->get();$policy=(array)DB::table('domains_sales_policies')->where('tld','com')->sole();unset($policy['id']);
for($i=1;$i<1500;$i++){
    $name='ending'.chr(97+intdiv($i,676)).chr(97+intdiv($i,26)%26).chr(97+$i%26);DB::table('domains_tlds')->insert([...$base,'tld'=>$name]);
    foreach($prices as $price){$row=(array)$price;unset($row['id']);DB::table('domains_prices')->insert([...$row,'tld'=>$name]);}
    DB::table('domains_sales_policies')->insert([...$policy,'tld'=>$name]);
}
$measure=static function()use($b,$source):array{
    $table=new SellTable([$b->serverId=>$b],1,true,static fn()=>[],false,$source);
    DB::enableQueryLog();DB::flushQueryLog();$at=microtime(true);$ids=$table->forSale($table->rows(null))->pluck('id')->all();$elapsed=microtime(true)-$at;$q=DB::getQueryLog();DB::disableQueryLog();
    $batches=count(array_filter($q,static fn($v)=>str_contains($v['query'],'from "domains_sales_policies"')));
    echo json_encode(['rows'=>count($ids),'queries'=>count($q),'policy_reads'=>$batches,'seconds'=>$elapsed])."\n";
    $maxNames=max([0,...array_map(static fn($v)=>count($v['bindings']),array_filter($q,static fn($v)=>str_contains($v['query'],'from "domains_prices"')))]);
    return [$ids,$batches,count($q),$maxNames];
};
[$ids,$batches,$queries,$maxNames]=$measure();same(1500,count($ids),'Large fixture consists of sale-ready bound TLDs');
[$again,$repeated,$againQueries]=$measure();same($ids,$again,'A new request keeps the same evaluator result');
// A thousand-row candidate set uses two bounded loads; the whole catalogue uses three.
$thousand=new SellTable([$b->serverId=>$b],1,true,static fn()=>[],false,$source);
DB::enableQueryLog();DB::flushQueryLog();$small=$thousand->forSale($thousand->rows(null)->where('id','<=',1000))->pluck('id');$smallQueries=count(DB::getQueryLog());DB::disableQueryLog();
same(1000,$small->count(),'Candidate scope is applied before sale evaluation');
same(1500,$thousand->forSale($thousand->rows(null))->count(),'Same request caches distinct candidate scopes separately');
$focused=static function()use($b,$source):array{$table=new SellTable([$b->serverId=>$b],1,true,static fn()=>[],false,$source);return $table->forSale($table->rows('com'))->pluck('id')->all();};
$original=$t->fresh()->getRawOriginal();$policyOriginal=(array)DB::table('domains_sales_policies')->where('tld','com')->sole();
$price=DB::table('domains_prices')->where('server_id',$b->serverId)->where('tld','com')->where('action','renew')->where('term',1)->sole();
foreach(['intent','facts','price','seller']as$change){
    if($change==='intent'){DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>'{"sell":false}']);}
    if($change==='facts'){DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>null]);}
    if($change==='price'){DB::table('domains_prices')->where('id',$price->id)->update(['retail'=>null]);}
    if($change==='seller'){DB::table('domains_sales_policies')->where('tld','com')->update(['register_enabled'=>false,'transfer_enabled'=>false]);}
    same([],$focused(),'Fresh candidate read reflects '.$change);
    DB::table('domains_tlds')->where('id',$t->id)->update($original);DB::table('domains_prices')->where('id',$price->id)->update((array)$price);DB::table('domains_sales_policies')->where('tld','com')->update($policyOriginal);
    same([(int)$t->id],$focused(),'Healthy membership recovers after '.$change);
}
// Membership is sent as one hex digit per four ids, never as an id list: any pattern stays exact and the statement stays small.
$intents=DB::table('domains_tlds')->where('server_id',$b->serverId)->pluck('catalogue_intent','id');
DB::table('domains_tlds')->where('server_id',$b->serverId)->whereRaw('(id % 3 = 0 or id % 7 = 0)')->update(['catalogue_intent'=>'{"sell":false}']);
$expected=DB::table('domains_tlds')->where('server_id',$b->serverId)->whereRaw('not (id % 3 = 0 or id % 7 = 0)')->orderBy('tld')->pluck('id')->map(static fn($id)=>(int)$id)->all();
$table=new SellTable([$b->serverId=>$b],1,true,static fn()=>[],false,$source);$query=$table->forSale($table->rows(null));
$size=static function($query):int{
    DB::enableQueryLog();DB::flushQueryLog();(clone $query)->orderBy('tld')->paginate(25,['*'],'page',3);$log=DB::getQueryLog();DB::disableQueryLog();
    return max(array_map(static fn(array $q):int=>strlen($q['query'])+array_sum(array_map(static fn($v):int=>strlen((string)$v),$q['bindings'])),$log));
};
$pager=(clone $query)->orderBy('tld')->paginate(25,['*'],'page',3);$bytes=$size($query)-$size($table->rows(null));
same($expected,(clone $query)->orderBy('tld')->pluck('id')->map(static fn($id)=>(int)$id)->all(),'Interleaved membership is exact');
same(count($expected),$pager->total(),'Filtered pagination counts exact membership');
same(array_slice($expected,50,25),array_map(static fn($row)=>(int)$row->id,$pager->items()),'Filtered pagination keeps sort and page');
echo json_encode(['members'=>count($expected),'membership_bytes'=>$bytes])."\n";
expect(count($expected)>800&&$bytes<1024,'Membership adds under 1 KiB to the page statement for '.count($expected).' members, got '.$bytes.' bytes');
foreach($intents as $id=>$intent){DB::table('domains_tlds')->where('id',$id)->update(['catalogue_intent'=>$intent]);}
// Sparse ids: the membership grows with the members, never with the span between their ids.
$moved=[];
foreach([1000,1001,1499] as $i=>$step){$id=(int)DB::table('domains_tlds')->where('server_id',$b->serverId)->orderBy('id')->skip($step)->value('id');$moved[$id]=4000000000+$i*977000003;}
foreach($moved as $id=>$far){DB::table('domains_tlds')->where('id',$id)->update(['id'=>$far]);}
DB::table('domains_tlds')->whereIn('id',array_values($moved))->limit(1)->update(['catalogue_intent'=>'{"sell":false}']);
$expected=DB::table('domains_tlds')->where('server_id',$b->serverId)->where('catalogue_intent','not like','%"sell":false%')->orderBy('tld')->pluck('id')->map(static fn($id)=>(int)$id)->all();
$memory=memory_get_usage();$table=new SellTable([$b->serverId=>$b],1,true,static fn()=>[],false,$source);$query=$table->forSale($table->rows(null));
same($expected,(clone $query)->orderBy('tld')->pluck('id')->map(static fn($id)=>(int)$id)->all(),'Sparse membership is exact');
$bytes=$size($query)-$size($table->rows(null));echo json_encode(['sparse_members'=>count($expected),'membership_bytes'=>$bytes,'memory'=>memory_get_usage()-$memory])."\n";
expect(count($expected)===1499&&$bytes<2048,'Ids billions apart add under 2 KiB, got '.$bytes.' bytes');
foreach($moved as $id=>$far){DB::table('domains_tlds')->where('id',$far)->update(['id'=>$id,'catalogue_intent'=>$intents[$id]]);}
$fresh=new SellTable([$b->serverId=>$b],1,true,static fn()=>[],false,$source);
same(1500,$fresh->forSale($fresh->rows(null))->count(),'Restored intents restore membership');
same(0,$fresh->forSale($fresh->rows(null)->where('id','<',0))->count(),'An empty candidate scope keeps no rows');
$calls=$reads->calls;$clock->instant=$now->modify('+2 days');pinStaffClock($clock->now());same([],$focused(),'Elapsed freshness cannot reuse sale membership');same($calls,$reads->calls,'Filter never reads a provider');
DB::table('roles')->where('id',$actor->role_id)->update(['permissions'=>'[]']);
try{$focused();expect(false,'Fresh filter requires current grants');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Revoked staff view refuses');}
echo json_encode(compact('queries','againQueries','smallQueries','maxNames'))."\n";
expect($queries<=100&&$againQueries<=100&&$smallQueries<=75,'Total local query budget is bounded by three 500-identity loads, or two for 1,000 candidates');
expect($maxNames<=510,'Every offer price read stays inside the 500-identity loader limit');
$nativeSuccessful=true;echo "Bounded sale filter passed.\n";
