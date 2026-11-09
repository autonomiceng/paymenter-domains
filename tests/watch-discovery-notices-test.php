<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-discovery.php';
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
$f=discoveryFixture();extract($f);$sweep();same(1,WatchName::count(),'Native discovery prerequisite');$w=WatchName::sole();
// Retained queued rows exercise lifecycle suppression without invoking a channel or provider.
$id=DB::table('domains_watch_notices')->insertGetId(['notice_key'=>str_repeat('a',64),'scope'=>str_repeat('b',64),'target_kind'=>'watch','watch_id'=>$w->id,'user_id'=>$owner->id,'kind'=>'expiring','family'=>'registry','episode_key'=>str_repeat('c',64),'observation_id'=>1,'state'=>'open','first_seen_at'=>$clock->at,'last_seen_at'=>$clock->at,'data'=>Crypt::encryptString('{}')]);
foreach(['reserved','render_failed','accepted','uncertain']as$i=>$state){DB::table('domains_watch_deliveries')->insert(['notice_id'=>$id,'user_id'=>$owner->id,'channel'=>'mail','delivery_key'=>hash('sha256',$state),'template_key'=>'domains_watch_expiring','state'=>$state,'reserved_at'=>$clock->at]);}
DB::table('services')->where('id',$service->id)->update(['status'=>'cancelled']);$sweep();
same('retired',$w->fresh()->state,'Last hosting source stops the watch');same('resolved',DB::table('domains_watch_notices')->where('id',$id)->value('state'),'A notice with handed-off evidence closes without claiming delivery suppression');
same(['suppressed','suppressed','accepted','uncertain'],DB::table('domains_watch_deliveries')->orderBy('id')->pluck('state')->all(),'Suppress only definitely unhanded deliveries');
same(0,DB::table('domains_watch_work')->where('watch_id',$w->id)->whereNotNull('claim')->count(),'Retirement invalidates work claims');
DB::table('services')->where('id',$service->id)->update(['status'=>'active']);$sweep();same('active',$w->fresh()->state,'A returning domain is monitored again');same('resolved',DB::table('domains_watch_notices')->where('id',$id)->value('state'),'Return never replays a closed old notice');same([],$dns->calls,'Notice lifecycle is local only');
echo "Retirement suppresses pending notices, preserves handed-off evidence and re-adds without replay.\n";$nativeSuccessful=true;
