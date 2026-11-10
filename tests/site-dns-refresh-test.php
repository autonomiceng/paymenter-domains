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
// A second template's edit lands after the batch consumed its first watch. The batch keeps its claim and page;
// the edit waits for the next batch and never repeats the first template's finished refresh.
$second=DB::transaction(fn()=>WatchProfile::create(['name'=>'Second DNS','enabled'=>true,'nameservers'=>['ns1.second.test','ns2.second.test'],
    'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$owner->id,'reason'=>'Second explicit template']));
DB::transaction(fn()=>WatchName::create(['user_id'=>$owner->id,'canonical_name'=>'second-placeholder.com','psl_revision'=>str_repeat('a',64),'state'=>'active',
    'ns_profile_id'=>$second->id,'profile_revision'=>$second->revision,'expected_dns'=>'platform','notice_preferences'=>[]]));
$quiet=new WatchWork($reader,new WatchDiscoveryWork(static fn()=>0),false,fn()=>$now);app()->instance(WatchWork::class,$quiet);
$hold();DB::table('domains_watch_work')->where('watch_id',$explicit->id)->whereIn('kind',['delegation','points'])->update(['due_at'=>$now+3600,'backoff_until'=>$now+3600,'attempts'=>3,'claim'=>null,'lease_until'=>null]);
DB::transaction(fn()=>$selected->update(['revision'=>$selected->revision+1,'nameservers'=>['ns1.batch.test','ns2.batch.test'],'edited_by'=>$owner->id,'reason'=>'Edit before the batch']));
$restart=true;
DB::listen(static function($query)use(&$restart,$second,$owner):void {
    if($restart && str_starts_with($query->sql,'update "domains_watch_schedule" set "cursor" = ?')){
        $restart=false;DB::afterCommit(static fn()=>DB::transaction(fn()=>$second->update(['revision'=>$second->revision+1,
            'nameservers'=>['ns1.second-edit.test','ns2.second-edit.test'],'edited_by'=>$owner->id,'reason'=>'Edit during the batch'])));
    }
});
$work->reconcileDefaults();expect(!$restart,'The second template edit landed after the batch consumed its first watch');
expect($checks->begin($explicit->id,$owner->id,'delegation',$now)!==null,'The active batch keeps its claim and refreshes the rest of its page');
$hold();$work->reconcileDefaults();
same(null,$checks->begin($watch->id,$owner->id,'delegation',$now),'The second template edit never repeats the finished first refresh');
// Overlapping callbacks for one effective default refresh once; a check that began after the first keeps its claim.
app()->instance(WatchWork::class,$work);
DB::transaction(fn()=>$selected->update(['revision'=>$selected->revision+1,'nameservers'=>['ns1.overlap.test','ns2.overlap.test'],'edited_by'=>$owner->id,'reason'=>'Edit before the overlap']));
$ticket=$checks->begin($watch->id,$owner->id,'delegation',$now);expect($ticket!==null,'The template edit refreshes the inherited watch');
$claim=fn()=>DB::table('domains_watch_work')->where('watch_id',$watch->id)->where('kind','delegation')->value('claim');
SiteDnsDefault::reconcile();
same($ticket->claim,$claim(),'A repeated refresh of the same default keeps the check that began after the first');
DB::transaction(fn()=>$selected->update(['revision'=>$selected->revision+1,'nameservers'=>['ns1.distinct.test','ns2.distinct.test'],'edited_by'=>$owner->id,'reason'=>'Distinct edit']));
same(null,$claim(),'A distinct template revision still refreshes the inherited watch');
// A delayed older callback reads the template under the request lock. A newer edit that lands during that read waits
// for the lock, so the older callback never queues its revision after the newer one started a check.
$newer=null;$delayed=true;
DB::listen(static function($query)use(&$delayed,&$newer,$selected,$owner,$checks,$watch,$now):void {
    if(!$delayed || !str_starts_with($query->sql,'select * from "domains_watch_profiles"')){return;}
    $delayed=false;
    $edit=static function()use(&$newer,$selected,$owner,$checks,$watch,$now):void {
        DB::transaction(fn()=>$selected->update(['revision'=>$selected->revision+1,'nameservers'=>['ns1.newer.test','ns2.newer.test'],'edited_by'=>$owner->id,'reason'=>'Newer edit']));
        $newer=$checks->begin($watch->id,$owner->id,'delegation',$now);
    };
    DB::transactionLevel()>0?DB::afterCommit($edit):$edit();
});
SiteDnsDefault::reconcile();
expect(!$delayed && $newer!==null,'The newer revision began its check while the older callback ran');
same($newer->claim,$claim(),'A delayed older callback keeps the check the newer revision began');
same(null,DB::table('domains_watch_schedule')->where('scope','enrollment_refresh')->value('data'),'A delayed older callback queues no stale revision');
// A reassignment after the page read decides with the new template, so the older refresh keeps its check.
app()->instance(WatchWork::class,$quiet);$hold();
DB::transaction(fn()=>$selected->update(['revision'=>$selected->revision+1,'nameservers'=>['ns1.moved.test','ns2.moved.test'],'edited_by'=>$owner->id,'reason'=>'Edit before the reassignment']));
$moved=null;
DB::listen(static function($query)use(&$moved,$watch,$second,$owner,$checks,$now):void {
    if($moved===null && str_starts_with($query->sql,'select * from "domains_watch_names" where "id" > ?')){
        $moved=false;
        DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>$second->id,'profile_revision'=>$second->fresh()->revision,'expected_dns'=>'platform','revision'=>DB::raw('revision + 1')]);
        $checks->enroll($watch->id,$owner->id,$now);$moved=$checks->begin($watch->id,$owner->id,'delegation',$now);
    }
});
$work->reconcileDefaults();expect($moved!==null && $moved!==false,'The reassigned watch began its new check after the page read');
same($moved->claim,$claim(),'An older template refresh keeps the reassigned watch check');
same([],$reader->calls,'Template maintenance makes no public DNS reads');
expect(in_array(Schema::getColumnType('domains_watch_schedule','data'),['text','longtext'],true),'Installed scheduler data is text, never a JSON column');
// A full enrollment batch advances with both a null payload and database-read JSON text.
for($i=1;$i<=25;$i++){
    DB::transaction(fn()=>WatchName::create(['user_id'=>$owner->id,'canonical_name'=>'cursor-'.$i.'.com','psl_revision'=>str_repeat('a',64),
        'state'=>'paused','ns_profile_id'=>null,'profile_revision'=>0,'notice_preferences'=>[]]));
}
$schedule=DB::table('domains_watch_schedule')->where('scope','enrollment');$schedule->update(['cursor'=>0,'data'=>null]);
$work->reconcileDefaults();$cursor=(int)(clone $schedule)->value('cursor');expect($cursor>0,'Native enrollment advances its first full batch from null data');
$work->reconcileDefaults();same(0,(int)(clone $schedule)->value('cursor'),'Native enrollment advances from saved JSON text to the final watch and completes the sweep');
same([],$reader->calls,'Enrollment cursor proof sends no DNS requests');
// A template refresh keeps its consumed progress across pages, and a template edited between pages gets real work.
$template=static fn(string $name)=>DB::transaction(fn()=>WatchProfile::create(['name'=>$name,'enabled'=>true,'nameservers'=>['ns1.'.$name.'.test','ns2.'.$name.'.test'],
    'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$owner->id,'reason'=>'Paged refresh']));
$assigned=static function(string $name,WatchProfile $p)use($owner,$now):int {
    $w=DB::transaction(fn()=>WatchName::create(['user_id'=>$owner->id,'canonical_name'=>$name,'psl_revision'=>str_repeat('a',64),'state'=>'active',
        'ns_profile_id'=>$p->id,'profile_revision'=>$p->revision,'expected_dns'=>'platform','notice_preferences'=>[]]))->fresh();
    foreach(['delegation','points'] as $kind){DB::table('domains_watch_work')->insert(['watch_id'=>$w->id,'kind'=>$kind,'due_at'=>$now+3600,
        'backoff_until'=>$now+3600,'watch_revision'=>(int)$w->revision,'attempts'=>3,'cursor'=>0]);}
    return (int)$w->id;
};
$pagedP=$template('paged-p');$pagedQ=$template('paged-q');$pIds=[];
for($i=1;$i<=30;$i++){$pIds[]=$assigned('paged-'.$i.'.com',$pagedP);}
$qId=$assigned('paged-q.com',$pagedQ);$all=[...$pIds,$qId];
$due=static fn()=>DB::table('domains_watch_work')->whereIn('watch_id',$all)->where('kind','delegation')->where('due_at',$now)->orderBy('watch_id')->pluck('watch_id')->map(fn($id)=>(int)$id)->all();
$holdPaged=static fn()=>DB::table('domains_watch_work')->whereIn('watch_id',$all)->update(['due_at'=>$now+3600]);
$schedule->update(['cursor'=>$pIds[0]-1,'data'=>null]);
DB::transaction(fn()=>$pagedP->update(['revision'=>$pagedP->revision+1,'nameservers'=>['ns1.paged-p2.test','ns2.paged-p2.test'],'edited_by'=>$owner->id,'reason'=>'Paged edit']));
same([],$due(),'A template edit waits for the next batch');
$work->reconcileDefaults();
same(array_slice($pIds,0,25),$due(),'The first page refreshes only the first 25 watches of the edited template');
$holdPaged();
DB::transaction(fn()=>$pagedQ->update(['revision'=>$pagedQ->revision+1,'nameservers'=>['ns1.paged-q2.test','ns2.paged-q2.test'],'edited_by'=>$owner->id,'reason'=>'Edit between pages']));
$work->reconcileDefaults();
same([...array_slice($pIds,25),$qId],$due(),'The next page finishes the first template and refreshes the joined template without repeating the first page');
$holdPaged();
$work->reconcileDefaults();$work->reconcileDefaults();
same([],$due(),'The wrapped pass stops at each refresh start and repeats no finished watch');
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
