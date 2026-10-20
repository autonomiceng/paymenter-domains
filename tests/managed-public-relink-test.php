<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{ManagedPublicConfig,ManagedPublicObservation,WatchAudit,WatchName,WatchSource,WatchDnsObservation};
use Paymenter\Extensions\Servers\Domains\Services\{ManagedPublicSignals,ManagedPublicWorkSchema,WatchDnsChecks};
extract(managedFixture(skipA3:true));$id=(int)$d->id;$uid=(int)$owner->id;$now=$time->now;
same('active',$watch->fresh()->state,'A3 failed postcommit relink fixture');same(0,DB::table(ManagedPublicWorkSchema::TABLE)->count(),'No A3 work after atomic failure');
$old=WatchDnsObservation::sole();$watchCount=WatchName::count();
Schema::create('domains_watch_notices',function(Blueprint $t){$t->id();$t->unsignedBigInteger('watch_id');$t->string('state');$t->unsignedBigInteger('resolved_at')->nullable();});
Schema::create('domains_watch_deliveries',function(Blueprint $t){$t->id();$t->unsignedBigInteger('notice_id');$t->string('state');$t->string('safe_code')->nullable();});
$n=DB::table('domains_watch_notices')->insertGetId(['watch_id'=>$watch->id,'state'=>'open']);
foreach(['reserved','render_failed','accepted','uncertain']as$state){DB::table('domains_watch_deliveries')->insert(['notice_id'=>$n,'state'=>$state]);}
$fail=true;WatchAudit::creating(static function($audit)use(&$fail){if($fail && $audit->event==='registration_managed'){throw new DomainException('Synthetic relink rollback');}});
expect(!$signals->enqueue($id,$uid,$now),'Audit failure rolls relink transaction back');same('active',$watch->fresh()->state,'Watch not partly relinked');same('watch',$source->fresh()->state,'Source not partly relinked');
same(0,ManagedPublicConfig::count(),'No partial configuration');same('reserved',DB::table('domains_watch_deliveries')->orderBy('id')->value('state'),'No partial suppression');
$fail=false;$hints=0;$signals=new ManagedPublicSignals($fake,true,fn()=>$time->now,$profile->id,function()use(&$hints){same(0,DB::transactionLevel(),'Hint after commit');$hints++;throw new RuntimeException('Lost queue hint');});
expect($signals->enqueue($id,$uid,$now),'Durable relink survives lost hint');same(1,$hints,'One postcommit hint attempted');same($watchCount,WatchName::count(),'Relink creates no watch');
same('linked',$watch->fresh()->state,'Watch tombstone linked');same('retired',$source->fresh()->state,'Source history retained');same(1,WatchSource::where('state','managed_link')->count(),'Same hosting target relinked');
same(['suppressed','suppressed','accepted','uncertain'],DB::table('domains_watch_deliveries')->orderBy('id')->pluck('state')->all(),'Only definitely unsent deliveries suppressed');
same('suppressed',DB::table('domains_watch_notices')->value('state'),'External expiry/NS notice closed');
same($old->started_at,WatchDnsObservation::sole()->started_at,'Original watch timestamp retained');same(0,ManagedPublicObservation::count(),'No watch fact copied as fresh managed evidence');
$config=ManagedPublicConfig::find($id);same($old->id,$config->dns_episode['predecessor_observation_id'],'Continuity reference preserved without evidence copying');
$checks=new WatchDnsChecks(true);expect(!$checks->begin($watch->id,$uid,'delegation',$now),'Managed watch jobs cannot restart');
$fake->answers['example.com|NS']=managedNs();expect($signals->observe($id,$uid,'delegation'),'Fresh read independent of lost hint');same($now,ManagedPublicObservation::sole()->started_at,'Fresh managed read has its own timestamp');
expect($signals->enqueue($id,$uid,$now),'Relink idempotent');same(2,DB::table(ManagedPublicWorkSchema::TABLE)->count(),'Idempotent managed work');same(1,$hints,'Observe and unchanged reconciliation do not recursively dispatch hints');
$nativeSuccessful=true;echo "Atomic generic relink, unsent suppression, continuity-only predecessor and lost postcommit hint passed.\n";
