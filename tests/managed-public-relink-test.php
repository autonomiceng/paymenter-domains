<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/site-dns-default.php';

require __DIR__.'/Support/managed-public.php';
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{ManagedPublicConfig,ManagedPublicObservation,WatchAudit,WatchName,WatchSource,WatchDnsObservation};
use Paymenter\Extensions\Servers\Domains\Services\{ManagedPublicSignals,ManagedPublicWorkSchema,WatchDnsChecks};
extract(managedFixture(skipA3:true,beforeConfirm:static function(array $f):void{
    DB::table('domains_watch_profiles')->where('id',$f['profile']->id)->update(['nameservers'=>json_encode(['ns1.platform.example','ns2.platform.example'])]);
    siteDnsFixture($f['profile']->id);
}));$id=(int)$d->id;$uid=(int)$owner->id;$now=$time->now;
// Initialize quota explicitly; unchanged site-default setup need not seed it.
\Paymenter\Extensions\Servers\Domains\Services\PublicDnsQuota::seed($now);
$pool=DB::table(\Paymenter\Extensions\Servers\Domains\Services\PublicDnsQuota::TABLE)->where('id',1);
same([0,[]],[(int)(clone $pool)->value('used'),json_decode((clone $pool)->value('active'),true)],'Fixture has no reserved DNS calls before its clock starts');
$pool->update(['window_at'=>$now]);
same('active',$watch->fresh()->state,'A3 failed postcommit relink fixture');same(0,DB::table(ManagedPublicWorkSchema::TABLE)->count(),'No A3 work after atomic failure');
$counter=staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,\Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::VIEW]);
\Illuminate\Support\Facades\Auth::setUser($counter);
same(1,\Paymenter\Extensions\Servers\Domains\Admin\Support\WatchFacts::usedBy($profile->id),'Pre-enrollment holding and its existing watch count once');
$old=WatchDnsObservation::sole();$watchCount=WatchName::count();
// Use the real installed notice schema and encrypted notice payload, retaining the four delivery outcomes.
Paymenter\Extensions\Servers\Domains\Services\WatchNoticeSchema::verify();
$n=Paymenter\Extensions\Servers\Domains\Models\WatchNotice::create([
    'notice_key'=>str_repeat('c',64),'scope'=>Paymenter\Extensions\Servers\Domains\Services\WatchNoticePreferences::scope($uid,'example.com'),
    'target_kind'=>'watch','watch_id'=>$watch->id,'user_id'=>$uid,'kind'=>'dns_changed','episode_key'=>str_repeat('d',64),
    'family'=>'dns','observation_id'=>$old->id,'state'=>'open','first_seen_at'=>$now,'last_seen_at'=>$now,
    'data'=>['domain'=>'example.com','channels'=>['mail']]])->id;
foreach(['reserved','render_failed','accepted','uncertain']as$state){
    DB::table('domains_watch_deliveries')->insert(['notice_id'=>$n,'user_id'=>$uid,'channel'=>'mail',
        'delivery_key'=>hash('sha256','synthetic-relink-'.$state),'template_key'=>'synthetic-relink','state'=>$state,'reserved_at'=>$now]);
}
$fail=true;WatchAudit::creating(static function($audit)use(&$fail){if($fail && $audit->event==='registration_managed'){throw new DomainException('Synthetic relink rollback');}});
expect(!$signals->enqueue($id,$uid,$now),'Audit failure rolls relink transaction back');same('active',$watch->fresh()->state,'Watch not partly relinked');same('watch',$source->fresh()->state,'Source not partly relinked');
same(0,ManagedPublicConfig::count(),'No partial configuration');same('reserved',DB::table('domains_watch_deliveries')->orderBy('id')->value('state'),'No partial suppression');
$fail=false;$hints=0;$signals=new ManagedPublicSignals($fake,true,fn()=>$time->now,$profile->id,function()use(&$hints){same(0,DB::transactionLevel(),'Hint after commit');$hints++;throw new RuntimeException('Lost queue hint');});
expect($signals->enqueue($id,$uid,$now),'Durable relink survives lost hint');same(1,$hints,'One postcommit hint attempted');same($watchCount,WatchName::count(),'Relink creates no watch');
same('linked',$watch->fresh()->state,'Watch tombstone linked');same('retired',$source->fresh()->state,'Source history retained');same(1,WatchSource::where('state','managed_link')->count(),'Same hosting target relinked');
same(['suppressed','suppressed','accepted','uncertain'],DB::table('domains_watch_deliveries')->orderBy('id')->pluck('state')->all(),'Only definitely unsent deliveries suppressed');
same('suppressed',DB::table('domains_watch_notices')->value('state'),'External expiry/NS notice closed');
same($old->started_at,WatchDnsObservation::sole()->started_at,'Original watch timestamp retained');same(0,ManagedPublicObservation::count(),'No watch fact copied as fresh managed evidence');
same(1,\Paymenter\Extensions\Servers\Domains\Admin\Support\WatchFacts::usedBy($profile->id),'Linked predecessor leaves the same single monitored holding');
$config=ManagedPublicConfig::find($id);same($old->id,$config->dns_episode['predecessor_observation_id'],'Continuity reference preserved without evidence copying');
$checks=new WatchDnsChecks(true);expect(!$checks->begin($watch->id,$uid,'delegation',$now),'Managed watch jobs cannot restart');
$fake->answers['example.com|NS']=managedNs();expect($signals->observe($id,$uid,'delegation'),'Fresh read independent of lost hint');same($now,ManagedPublicObservation::sole()->started_at,'Fresh managed read has its own timestamp');
expect($signals->enqueue($id,$uid,$now),'Relink idempotent');same(2,DB::table(ManagedPublicWorkSchema::TABLE)->count(),'Idempotent managed work');same(1,$hints,'Observe and unchanged reconciliation do not recursively dispatch hints');
// An explicit usable template counts as owned configuration; inherited domains remain a separate count.
DB::table('domains_managed_public_config')->where('domain_id',$id)->update(['profile_id'=>$profile->id]);
$usage=\Paymenter\Extensions\Servers\Domains\Admin\Support\WatchFacts::class;
same(['checked'=>1,'inherited'=>0,'unchecked'=>0],$usage::usage($profile->id),'Explicit registered template uses the non-inherited query');
DB::table('domains_managed_public_config')->where('domain_id',$id)->update(['profile_id'=>null]);
same(['checked'=>1,'inherited'=>1,'unchecked'=>0],$usage::usage($profile->id),'The same holding inherits the default exactly once');
foreach([['explicit.example.com',$profile->id],['inherited.example.com',null]] as [$name,$assigned]){
    DB::transaction(fn()=>WatchName::create(['user_id'=>$uid,'canonical_name'=>$name,'psl_revision'=>str_repeat('a',64),'state'=>'active',
        'ns_profile_id'=>$assigned,'profile_revision'=>$assigned?2:0,'expected_dns'=>'platform','notice_preferences'=>[]]));
}
$templates=[$profile->id];
foreach(range(1,3) as $n){$templates[]=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Models\WatchProfile::create(['name'=>'Other usage '.$n,'enabled'=>true,
    'nameservers'=>['ns.other'.$n.'.example'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$staff->id,'reason'=>'Synthetic usage template']))->id;}
foreach($templates as $template){
    DB::flushQueryLog();DB::enableQueryLog();$actual=$usage::usage($template);$queries=DB::getQueryLog();DB::disableQueryLog();
    same($template===$profile->id?['checked'=>3,'inherited'=>2,'unchecked'=>0]:['checked'=>0,'inherited'=>0,'unchecked'=>0],$actual,'Combined usage counts each domain once');
    $reads=fn(string $table)=>count(array_filter($queries,fn($q)=>str_starts_with($q['query'],'select')&&str_contains($q['query'],'from "'.$table.'"')));
    expect($reads('domains_watch_profiles')<=2,'Own and inherited counts share at most two profile reads');
    expect($reads('settings')<=1,'Own and inherited counts share one default read');
}
$nativeSuccessful=true;echo "Atomic generic relink, unsent suppression, continuity-only predecessor and lost postcommit hint passed.\n";
