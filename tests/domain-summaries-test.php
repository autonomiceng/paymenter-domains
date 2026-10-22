<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/watch-notices.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,DomainSummaryContext};
use Paymenter\Extensions\Servers\Domains\Dns\DnsReading;
$f=noticeFixture();extract($f);require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();$reader=new DomainSummaries();
noticeRegistry($f,$now);noticeDns($f,$now);
DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','points')->update(['due_at'=>$now]);
$t=$dns->begin($id,$uid,'points',$now);expect($t!==null,'Points ticket');expect($dns->finish($t,new DnsReading('points',website:false,email:false),$now+1),'Complete empty points');
$d=$reader->one($owner,'watch',$id,$now+2);expect($d!==null,'Owned watch DTO');same('example.com',$d->name,'Canonical name');
same(['domain.dns.external','domain.points.external_empty'],$d->dnsKeys,'Known empty external label');same(false,$d->freshness['registry']['stale'],'Fresh registry');
same('Public registry',$d->expirySource,'No endpoint URL in customer DTO');same(1,count($d->relatedServices),'Native owner-safe hosting link');same('www.example.com',$d->hostnames[0],'Mapped primary hostname');
same(null,$reader->one($other,'watch',$id,$now+2),'Foreign ID refuses');same([], $reader->services($other,[$service->id],$now+2),'Foreign hosting link refuses');
$dto=json_encode($d,JSON_THROW_ON_ERROR);foreach(['account_fingerprint','source_digest','192.0.2.1','rdap.example.com','registrar_key','PRIVATE-'] as $secret){expect(!str_contains($dto,$secret),'Customer whitelist '.$secret);}
$before=DB::table('domains_watch_work')->get()->toJson();$reader->batch($owner,[],[$id],$now+2);same($before,DB::table('domains_watch_work')->get()->toJson(),'GET projection does not write work');
// Every mutable source/owner/profile identity invalidates the projection, even within the normal TTL.
$property->update(['value'=>'new.example.com']);$stale=$reader->one($owner,'watch',$id,$now+2);expect(!in_array('domain.points.external_empty',$stale->dnsKeys,true),'Changed hostname cannot claim empty points');same([], $stale->relatedServices,'Stale source link omitted');
$property->update(['value'=>'www.example.com']);$order->update(['user_id'=>$other->id]);$moved=$reader->one($owner,'watch',$id,$now+2);expect(!in_array('domain.points.external_empty',$moved->dnsKeys,true),'Native order owner invalidates facts');same([],$moved->relatedServices,'Moved order link omitted');$order->update(['user_id'=>$owner->id]);
DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['revision'=>2]);$stale=$reader->one($owner,'watch',$id,$now+2);expect(!in_array('domain.points.external_empty',$stale->dnsKeys,true),'Profile revision invalidates facts');DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['revision'=>1]);
// Partial and stale facts never produce either complete-empty label.
DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','points')->update(['due_at'=>$now+10]);$t=$dns->begin($id,$uid,'points',$now+10);expect($t!==null,'Partial ticket');$dns->finish($t,new DnsReading('points',website:false,email:null),$now+11);
$d=$reader->one($owner,'watch',$id,$now+12);expect(!array_intersect(['domain.points.platform_empty','domain.points.external_empty'],$d->dnsKeys),'Unknown email refuses empty labels');
// A failed read preserves the independent last-known facts without turning them into current badges.
DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','points')->update(['due_at'=>$now+20,'backoff_until'=>null]);$t=$dns->begin($id,$uid,'points',$now+20);expect($t!==null,'Unknown ticket');$dns->finish($t,new DnsReading('points'),$now+21);
$stale=$reader->one($owner,'watch',$id,$now+22);same(false,$stale->lastKnown['website']['value'],'Partial website retained as marked history');same(true,$stale->lastKnown['website']['stale'],'Retained value explicitly stale');same(true,$stale->lastKnown['website']['partial'],'Partial history explicitly marked');same(true,$stale->lastKnown['website']['inScope'],'History scope marked');
expect(!array_intersect(['domain.points.platform_empty','domain.points.external_empty'],$reader->one($owner,'watch',$id,$now+200000)->dnsKeys),'Expired TTL refuses empty labels');
// Successful non-fact registry outcomes have a bounded readable state, never expiry authority.
foreach(['not_registered','no_coverage'] as $n=>$reason){$at=$now+100+$n*50;DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','expiry')->update(['due_at'=>$at,'backoff_until'=>null]);$ticket=$registry->begin($id,$uid,$at);expect($ticket!==null,'Semantic registry ticket');$registry->finish($ticket,['result'=>$reason==='not_registered'?'not_registered':'unknown','reason'=>$reason,'safe_code'=>$reason,'expiry'=>null,'precision'=>null,'source'=>null,'status'=>[]],str_repeat('c',64),$at+1);$state=$reader->one($owner,'watch',$id,$at+2);expect(in_array('watch.'.$reason,$state->stateKeys,true),'Current semantic registry state');same(null,$state->expiry,'Old expiry does not masquerade as current');if($reason==='not_registered'){same('domain.registration.unknown',$state->registrationKey,'No registration badge contradicts registry absence');}same(false,$state->freshness['registry']['stale'],'Current semantic check is fresh');same(true,$reader->one($owner,'watch',$id,$at+700000)->freshness['registry']['stale'],'Old semantic check is stale');expect(!in_array('watch.'.$reason,$reader->one($owner,'watch',$id,$at+700000)->stateKeys,true),'Old semantic registry state suppressed');}
// Query count stays constant as target count grows; no per-row Eloquent reads.
$clone=function(string $table,int $id,array $changes=[]):int{$row=(array)DB::table($table)->where('id',$id)->first();unset($row['id']);return (int)DB::table($table)->insertGetId([...$row,...$changes]);};
$ids=[$id];for($i=0;$i<12;$i++){
    $name=placeholderComFixtureName('extra'.$i.'-placeholder.com');$wid=$clone('domains_watch_names',$id,['canonical_name'=>$name]);$ids[]=$wid;
    $sid=$clone('services',$service->id,['order_id'=>$clone('orders',$order->id)]);
    $clone('properties',$property->id,['model_id'=>$sid,'value'=>'www.'.$name]);
    $sourceId=(int)DB::table('domains_watch_sources')->where('watch_id',$id)->value('id');
    $clone('domains_watch_sources',$sourceId,['watch_id'=>$wid,'service_id'=>$sid,'hostname'=>'www.'.$name,'registrable_name'=>$name]);
    foreach(DB::table('domains_watch_observations')->where('watch_id',$id)->pluck('id') as $oid){$clone('domains_watch_observations',(int)$oid,['watch_id'=>$wid]);}
    foreach(DB::table('domains_watch_work')->where('watch_id',$id)->pluck('id') as $job){$clone('domains_watch_work',(int)$job,['watch_id'=>$wid]);}
}
DB::enableQueryLog();DB::flushQueryLog();$reader->batch($owner,[],[$id],$now+12);$single=count(DB::getQueryLog());DB::flushQueryLog();$all=$reader->batch($owner,[],$ids,$now+12);$many=count(DB::getQueryLog());same(13,count($all),'Batch includes independent names');same($single,$many,'Constant batch query count');DB::disableQueryLog();
$nativeSuccessful=true;echo "Owner-safe batched summaries, local stamped freshness, independent axes and safe links passed.\n";
