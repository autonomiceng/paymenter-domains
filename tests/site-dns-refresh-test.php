<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
require __DIR__.'/Support/FakeDnsReader.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,WatchDnsChecks,WatchWork,WatchDiscoveryWork,PublicDnsQuota};
use Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader;
// Native after-commit hooks and the durable enrollment cursor determine whether a real read can start.
config(['domains.site_dns_default.nameservers'=>['ns1.beco.test','ns2.beco.test']]);extract(dnsFixture());
$now=1800000000;$reader=new FakeDnsReader();$checks=new WatchDnsChecks(true);
DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>null,'profile_revision'=>0,'expected_dns'=>null]);
$work=new WatchWork($reader,new WatchDiscoveryWork(static fn()=>0),true,fn()=>$now);app()->instance(WatchWork::class,$work);
$work->reconcileDefaults();
same(3,DB::table('domains_watch_work')->where('watch_id',$watch->id)->count(),'Enrollment schedules registry and DNS without an outer transaction');
$hold=static function()use($watch,$now):void {DB::table('domains_watch_work')->where('watch_id',$watch->id)->whereIn('kind',['delegation','points'])->update(['due_at'=>$now+3600,'backoff_until'=>$now+3600,'attempts'=>3,'claim'=>null,'lease_until'=>null]);};
$hold();
DB::table('domains_watch_schedule')->where('scope','enrollment')->update(['cursor'=>987,'data'=>json_encode(['refresh_profiles'=>[999],'enrollment_claim'=>'retained-claim','enrollment_until'=>$now+500])]);
$beforeUnused=DB::table('domains_watch_schedule')->where('scope','enrollment')->first();
DB::transaction(fn()=>WatchProfile::create(['name'=>'Unrelated DNS','enabled'=>true,'nameservers'=>['ns1.other.test','ns2.other.test'],
    'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$owner->id,'reason'=>'Another explicit template']));
same(null,$checks->begin($watch->id,$owner->id,'delegation',$now),'An unrelated DNS template must preserve inherited-watch retry backoff');
same((array)$beforeUnused,(array)DB::table('domains_watch_schedule')->where('scope','enrollment')->first(),'Unused template leaves unrelated claims and cursor intact');
DB::table('domains_watch_schedule')->where('scope','enrollment')->update(['cursor'=>0,'data'=>null]);
$hold();SiteDnsDefault::ensure();
same(null,$checks->begin($watch->id,$owner->id,'delegation',$now),'Unchanged setup must preserve inherited-watch retry backoff');
$selected=SiteDnsDefault::profile();
$explicit=DB::transaction(fn()=>WatchName::create(['user_id'=>$owner->id,'canonical_name'=>'explicit-placeholder.com','psl_revision'=>str_repeat('a',64),'state'=>'active','ns_profile_id'=>$selected->id,'profile_revision'=>$selected->revision,'expected_dns'=>'platform','notice_preferences'=>[]]));
$order2=App\Models\Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);$host2=App\Models\Service::create([...$service->only(['product_id','plan_id','status','quantity','price','currency_code']),'user_id'=>$owner->id,'order_id'=>$order2->id]);
$host2->properties()->create(['key'=>'domain','value'=>'explicit-placeholder.com']);$copy=$source->replicate();$copy->watch_id=$explicit->id;$copy->registrable_name='explicit-placeholder.com';$copy->hostname='explicit-placeholder.com';$copy->service_id=$host2->id;DB::transaction(fn()=>$copy->save());
$work->enrollWatch($explicit->id,$owner->id);DB::table('domains_watch_work')->where('watch_id',$explicit->id)->whereIn('kind',['delegation','points'])->update(['due_at'=>$now+3600,'backoff_until'=>$now+3600]);

DB::transaction(fn()=>$selected->update(['revision'=>$selected->revision+1,'nameservers'=>['ns1.changed.test','ns2.changed.test'],'edited_by'=>$owner->id,'reason'=>'Changed selected nameservers']));
expect($checks->begin($watch->id,$owner->id,'delegation',$now)!==null,'Editing the selected site template still refreshes inherited DNS work');
expect($checks->begin($explicit->id,$owner->id,'delegation',$now)!==null,'Editing the site template also refreshes explicit assignments to it');
$hold();
// Interleave a real default restart after this batch has read its cursor, with eager processing disabled.
app()->instance(WatchWork::class,new WatchWork($reader,new WatchDiscoveryWork(static fn()=>0),false,fn()=>$now));
$restart=true;
DB::listen(static function($query)use(&$restart):void {
    if($restart && str_starts_with($query->sql,'select * from "domains_watch_names" where "id" > ?')){
        $restart=false;SiteDnsDefault::reconcile();
    }
});
$work->reconcileDefaults();expect(!$restart,'Concurrent restart was injected after the enrollment cursor read');
$data=json_decode(DB::table('domains_watch_schedule')->where('scope','enrollment')->value('data'),true);
same(true,$data['refresh_dns']??false,'A newer default restart must survive the older enrollment batch');
$work->reconcileDefaults();
expect($checks->begin($watch->id,$owner->id,'delegation',$now)!==null,'The retained restart refreshes the inherited watch on the next batch');
same([],$reader->calls,'Template maintenance makes no public DNS reads');
expect(in_array(Schema::getColumnType('domains_watch_schedule','data'),['text','longtext'],true),'Installed scheduler data is text, never a JSON column');
// A full enrollment batch advances with both a null payload and database-read JSON text.
for($i=1;$i<=25;$i++){
    DB::transaction(fn()=>WatchName::create(['user_id'=>$owner->id,'canonical_name'=>'cursor-'.$i.'.com','psl_revision'=>str_repeat('a',64),
        'state'=>'paused','ns_profile_id'=>null,'profile_revision'=>0,'notice_preferences'=>[]]));
}
$schedule=DB::table('domains_watch_schedule')->where('scope','enrollment');$schedule->update(['cursor'=>0,'data'=>null]);
$work->reconcileDefaults();$cursor=(int)(clone $schedule)->value('cursor');expect($cursor>0,'Native enrollment advances its first full batch from null data');
SiteDnsDefault::reconcile();$work->reconcileDefaults();same($cursor,(int)(clone $schedule)->value('cursor'),'Native enrollment advances a full batch from saved JSON text');
$work->reconcileDefaults();same(0,(int)(clone $schedule)->value('cursor'),'Native enrollment reaches the final watch and completes the sweep');
same([],$reader->calls,'Enrollment cursor proof sends no DNS requests');
// Without eligible managed holdings a site-wide refresh starts no managed sweep.
PublicDnsQuota::seed($now);DB::table(PublicDnsQuota::TABLE)->where('id',1)->update(['sweep_due_at'=>$now+86400,'sweep_cursor'=>7]);
SiteDnsDefault::reconcile();
same([$now+86400,7],array_map('intval',array_values((array)DB::table(PublicDnsQuota::TABLE)->where('id',1)->select('sweep_due_at','sweep_cursor')->first())),'A default refresh with no eligible managed holdings leaves the managed sweep alone');
// Compile the actual installer through Laravel's MySQL grammar for the disposable database proof.
$nativeSchema=Schema::getFacadeRoot();$mysql=new \Illuminate\Database\MySqlConnection(DB::connection()->getPdo(),'watch_schedule_proof');
try{
    Schema::swap($mysql->getSchemaBuilder());
    $queries=$mysql->pretend(fn()=>\Paymenter\Extensions\Servers\Domains\Services\WatchScheduleSchema::install());
}finally{Schema::swap($nativeSchema);}
$ddl=array_values(array_filter(array_column($queries,'query'),fn($sql)=>str_starts_with($sql,'create table `domains_watch_schedule`')));
same(1,count($ddl),'The actual installer emits one scheduler table');expect(str_contains($ddl[0],'`data` longtext null'),'The native MySQL schema uses nullable LONGTEXT data');
echo 'WATCH_SCHEDULE_MYSQL_DDL='.json_encode($ddl[0],JSON_THROW_ON_ERROR)."\n";
$nativeSuccessful=true;echo "Site DNS refresh preserves unrelated work.\n";
