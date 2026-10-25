<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adoption-confirm.php';
use App\Models\{Order, Product, Server, Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain, WatchAudit, WatchName, WatchProfile, WatchProductMap, WatchSource, WatchDnsObservation};
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionEnrollment, ManagedPublicWorkSchema, WatchDnsSchema, WatchDnsChecks};
use Paymenter\Extensions\Servers\Domains\Dns\DnsReading;
extract(adoptionCommitFixture()); WatchDnsSchema::install(); ManagedPublicWorkSchema::install(); \Paymenter\Extensions\Servers\Domains\Services\ManagedPublicSchema::install();
$hostingServer = Server::create(['name'=>'Synthetic hosting','extension'=>'SyntheticHosting','type'=>'server','enabled'=>true]);
$hostingProduct = Product::create(['name'=>'Hosting','slug'=>'adoption-hosting','category_id'=>$product->category_id,'server_id'=>$hostingServer->id,'hidden'=>true,'allow_quantity'=>'combined']);
$hostingPlan = $hostingProduct->plans()->create(['name'=>'Monthly','type'=>'recurring','billing_period'=>1,'billing_unit'=>'month']);
$hostingOrder = Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
$hosting = Service::create(['user_id'=>$owner->id,'order_id'=>$hostingOrder->id,'product_id'=>$hostingProduct->id,'plan_id'=>$hostingPlan->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
$hosting->properties()->create(['key'=>'domain','value'=>'www.example.com']);
$profile = DB::transaction(fn()=>WatchProfile::create(['name'=>'Synthetic defaults','nameservers'=>['ns1.platform.test','ns2.platform.test'], 'website_endpoints'=>['ips'=>['192.0.2.1']], 'email_endpoints'=>['exact'=>['mx.platform.test']], 'edited_by'=>$staff->id,'reason'=>'Synthetic composition']));
DB::transaction(fn()=>$profile->fill(['enabled'=>true,'revision'=>2,'reason'=>'Synthetic enabled composition'])->save());
$map = DB::transaction(fn()=>WatchProductMap::create(['product_id'=>$hostingProduct->id,'property_key'=>'domain','expected_ns_profile_id'=>$profile->id,'edited_by'=>$staff->id,'reason'=>'Synthetic map']));
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>true]);
$watch = DB::transaction(fn()=>WatchName::create(['user_id'=>$owner->id,'canonical_name'=>'example.com','psl_revision'=>str_repeat('a',64),'state'=>'active','expected_dns'=>'platform','ns_profile_id'=>$profile->id,'profile_revision'=>$profile->revision,'notice_preferences'=>['expiry'=>true,'dns'=>true]]));
$source = DB::transaction(fn()=>WatchSource::create(['user_id'=>$owner->id,'service_id'=>$hosting->id,'map_id'=>$map->id,'map_revision'=>1,'hostname'=>'www.example.com','registrable_name'=>'example.com','watch_id'=>$watch->id,'state'=>'watch','source_digest'=>str_repeat('b',64),'last_seen_at'=>$clock->now()->getTimestamp()]));
$foreign = DB::transaction(fn()=>WatchName::create(['user_id'=>$other->id,'canonical_name'=>'example.com','psl_revision'=>str_repeat('a',64),'state'=>'active','notice_preferences'=>[]]));
$checks = new WatchDnsChecks(true); $now=$clock->now()->getTimestamp(); expect($checks->enroll($watch->id,$owner->id,$now),'External DNS work fixture');
$first = $checks->begin($watch->id,$owner->id,'delegation',$now); expect($first!==null,'Original watch claim');
expect($checks->finish($first,new DnsReading('delegation','platform'),$now+1),'Original NS history');
DB::table('domains_watch_work')->where('watch_id',$watch->id)->update(['due_at'=>$now,'backoff_until'=>null,'cursor'=>7]);
$inflight = $checks->begin($watch->id,$owner->id,'delegation',$now+2); expect($inflight!==null,'Old in-flight watch ticket');
$workBefore = DB::table('domains_watch_work')->where('watch_id',$watch->id)->orderBy('id')->get(); $observationId=$watch->fresh()->last_observation_id;
$fail=true; WatchAudit::creating(static function($audit)use(&$fail):void {if($fail&&$audit->event==='registration_adopted'){throw new DomainException('Injected enrollment audit failure');}});
$r=$reader->preview($target,$context); same('ready',$r->code,'Watch peer does not block adoption');$a=Adoption::findOrFail($r->previewId);$money=scenarioMoney();$calls=count($wire->calls);
$d=$commit->confirm($a->request_key,$a->request_digest,$staff);
same('committed',$a->fresh()->state,'Enrollment failure never rolls back committed adoption');
same('active',$watch->fresh()->state,'Failed enrollment atomically retains external target'); same('watch',$source->fresh()->state,'Failed relink rolls source back');same(0,DB::table(ManagedPublicWorkSchema::TABLE)->count(),'Failure leaves no partial managed work');
// Staff profile edits do not require an unbounded watch revision fan-out.
DB::transaction(fn()=>$profile->fill(['nameservers'=>['ns3.platform.test'],'enabled'=>false,'revision'=>3,'reason'=>'Reviewed routing change'])->save());DB::transaction(fn()=>$profile->fill(['enabled'=>true,'revision'=>4,'reason'=>'Reviewed current routing profile'])->save());
$fail=false; $enrollment=new AdoptionEnrollment($clock);
// Simulate a database ignoring a required work insert without raising an exception.
foreach([[ManagedPublicWorkSchema::TABLE,"new.kind='delegation'"],[ManagedPublicWorkSchema::TABLE,"new.kind='points'"],['domains_observation_queues','1'],['domains_observation_work','1']] as [$table,$condition]){
    DB::statement('CREATE TRIGGER refuse_enrollment BEFORE INSERT ON "'.$table.'" WHEN '.$condition.' BEGIN SELECT RAISE(IGNORE); END');
    $refused=false;
    try{$enrollment->repair($d->id);}catch(DomainException){$refused=true;}
    finally{DB::statement('DROP TRIGGER refuse_enrollment');}
    expect($refused,'Missing required '.$table.' '.$condition.' must refuse enrollment');
    expect(!isset($d->fresh()->observation['adoption_enrollment']),'Missing work never commits enrollment marker');
    same(['active','watch',0],[$watch->fresh()->state,$source->fresh()->state,DB::table(ManagedPublicWorkSchema::TABLE)->count()],'Missing work rolls back relink and partial work');
}
// Retained rows with the right unique key but another identity also refuse.
foreach(['domains_observation_queues','domains_observation_work',ManagedPublicWorkSchema::TABLE] as $table){
    $row=match($table){
        'domains_observation_queues'=>['server_id'=>$d->server_id,'account_fingerprint'=>str_repeat('f',64)],
        'domains_observation_work'=>['server_id'=>$d->server_id,'account_fingerprint'=>str_repeat('f',64),'domain_id'=>$d->id,'generation'=>$d->authority_generation,'phase'=>'holding','due_at'=>$now],
        default=>['domain_id'=>$d->id,'user_id'=>$other->id,'generation'=>$d->authority_generation,'account_fingerprint'=>$d->account_fingerprint,'kind'=>'delegation','due_at'=>$now,'target_stamp'=>str_repeat('f',64)],
    };
    $id=DB::table($table)->insertGetId($row);$retained=DB::table($table)->where('id',$id)->first();$refused=false;
    try{$enrollment->repair($d->id);}catch(DomainException){$refused=true;}
    expect($refused,'Conflicting retained '.$table.' identity must refuse');
    expect(!isset($d->fresh()->observation['adoption_enrollment']),'Conflicting work cannot consume marker');
    same((array)$retained,(array)DB::table($table)->where('id',$id)->first(),'Conflicting work is retained without repair');
    DB::table($table)->where('id',$id)->delete();
}
// A late save veto occurs only after all required work has been staged.
$staged=[];$failMarker=true;
Domain::updating(static function(Domain $row)use(&$staged,&$failMarker){
    if($failMarker&&isset($row->observation['adoption_enrollment'])){
        $staged=[DB::table(ManagedPublicWorkSchema::TABLE)->count(),DB::table('domains_observation_queues')->count(),DB::table('domains_observation_work')->count()];
        return false;
    }
});
$refused=false;try{$enrollment->repair($d->id);}catch(DomainException $e){same('Adoption enrollment marker was not retained.',$e->getMessage(),'Exact late-save refusal');$refused=true;}
expect($refused,'Late enrollment marker veto refuses');same([2,1,1],$staged,'Marker veto follows every work insert');
same(['active','watch',0,0,0],[$watch->fresh()->state,$source->fresh()->state,DB::table(ManagedPublicWorkSchema::TABLE)->count(),DB::table('domains_observation_queues')->count(),DB::table('domains_observation_work')->count()],'Late veto rolls back relink and every work family');
expect(!isset($d->fresh()->observation['adoption_enrollment']),'Late veto cannot consume enrollment marker');
$failMarker=false;
// The account cursor repairs the adoption after both early and late enrollment failures.
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
app()->instance(AdoptionEnrollment::class,$enrollment);
$neighbor=Domain::create(['user_id'=>$other->id,'server_id'=>$d->server_id,'service_id'=>$hosting->id,'registrar_key'=>$d->registrar_key,'account_fingerprint'=>$d->account_fingerprint,'domain'=>'neighbor-placeholder.com','sld'=>'neighbor-placeholder','tld'=>'com','status'=>'cancelled']);
DB::table('domains_runtime_turns')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,'domain_cursor'=>$neighbor->id,'due_at'=>0]);
$runtime=new \Paymenter\Extensions\Servers\Domains\Services\RuntimeWork($clock,new \Paymenter\Extensions\Servers\Domains\Services\RuntimePorts);
$runtime->tick();same(0,(int)DB::table('domains_runtime_turns')->value('domain_cursor'),'Exhausted cursor wraps for earlier adopted row');
expect(!isset($d->fresh()->observation['adoption_enrollment']),'First turn only wraps cursor');
DB::table('domains_runtime_turns')->update(['due_at'=>0]);$runtime->tick();
same(null,$neighbor->fresh()->observation,'Non-adopted neighbor never acquires adoption marker');
same('linked',$watch->fresh()->state,'Watch becomes retained tombstone');same(false,$watch->fresh()->notice_preferences['expiry'],'External expiry notice resolved');same(true,$watch->fresh()->notice_preferences['dns'],'Independent DNS preference preserved');
same($observationId,$watch->fresh()->last_observation_id,'Original NS history pointer retained without fresh-copy claims');same(1,WatchDnsObservation::count(),'No fabricated managed DNS facts');
same('retired',$source->fresh()->state,'Prior source retained');$linked=WatchSource::where('state','managed_link')->sole();same($hosting->id,(int)$linked->service_id,'Same hosting service relinked');same($d->id,(int)$linked->managed_domain_id,'Managed owner target');
same('active',$foreign->fresh()->state,'Other owner watch unchanged');same(2,DB::table(ManagedPublicWorkSchema::TABLE)->count(),'Managed delegation and points ensured');same(1,DB::table('domains_observation_work')->where('domain_id',$d->id)->count(),'Ordinary registrar observation enrolled');
foreach(DB::table(ManagedPublicWorkSchema::TABLE)->get()as$work){same('awaiting_acceptance',$work->safe_code,'DNS execution remains dormant');same((int)$observationId,(int)$work->origin_observation_id,'NS episode origin retained');same($d->authority_generation,(int)$work->generation,'Managed generation stamped');same(4,(int)$work->profile_revision,'Enrollment uses the locked current routing revision');}
$old=DB::table('domains_watch_work')->where('watch_id',$watch->id)->orderBy('id')->get();same($workBefore->pluck('id')->all(),$old->pluck('id')->all(),'Old DNS work IDs retained');same([7,7],$old->pluck('cursor')->all(),'Old DNS cursors retained');
expect(!$checks->finish($inflight,new DnsReading('delegation','external'),$now+3),'Old in-flight watch result cannot project after adoption');expect(!$checks->enroll($watch->id,$owner->id,$now+4),'Managed name never re-enrolls as external watch');
$auditCount=WatchAudit::count();$marker=$d->fresh()->observation;
$queries=[];DB::listen(static function($q)use(&$queries):void{$queries[]=$q->sql;});
$enrollment->repair($d->id);
same(1,count($queries),'Current seed marker skips discovery and all native locks');
// Each mismatched seed field reaches discovery, but the locked current marker still wins.
$staleField=null;
Domain::retrieved(static function(Domain $row)use(&$staleField,$d):void{
    if($staleField!==null&&DB::transactionLevel()===0&&(int)$row->id===(int)$d->id){
        $prior=$row->observation;$prior['adoption_enrollment'][$staleField]=-1;$row->observation=$prior;$staleField=null;
    }
});
foreach(['adoption_id','generation']as$field){$staleField=$field;$queries=[];$enrollment->repair($d->id);expect(count($queries)>1,'Changed seed '.$field.' cannot skip locked recheck');same($marker,$d->fresh()->observation,'Locked marker retains original enrollment');}
$commit->confirm($a->request_key,$a->request_digest,$staff);
same($auditCount,WatchAudit::count(),'Repair/replay does not duplicate relink audit');same($marker,$d->fresh()->observation,'Repair/replay preserves original enrollment');same(2,DB::table(ManagedPublicWorkSchema::TABLE)->count(),'No duplicate managed work');same($calls,count($wire->calls),'Relink/repair never performs network');same($money,scenarioMoney(),'Relink never changes finances');
$nativeSuccessful=true;echo "Postcommit adoption relink, atomic managed enrollment, NS history and durable repair passed\n";
