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
same(['discovery_retired','discovery_retired'],DB::table('domains_watch_deliveries')->where('state','suppressed')->orderBy('id')->pluck('safe_code')->all(),'Real retirement keeps its own reason');
same(0,DB::table('domains_watch_work')->where('watch_id',$w->id)->whereNotNull('claim')->count(),'Retirement invalidates work claims');
DB::table('services')->where('id',$service->id)->update(['status'=>'active']);$sweep();same('active',$w->fresh()->state,'A returning domain is monitored again');same('resolved',DB::table('domains_watch_notices')->where('id',$id)->value('state'),'Return never replays a closed old notice');same([],$dns->calls,'Notice lifecycle is local only');
// A suspended hosting service pauses the watch: unhanded deliveries stop, the customer's saved choice stays, and return replays nothing.
$scope=Paymenter\Extensions\Servers\Domains\Services\WatchNoticePreferences::scope($owner->id,$w->canonical_name);
foreach([$scope,Paymenter\Extensions\Servers\Domains\Services\WatchNoticePreferences::account($owner->id)] as $key){DB::table('domains_watch_preferences')->insertOrIgnore(['scope'=>$key,'user_id'=>$owner->id,'enabled'=>true]);}
$preferences=Paymenter\Extensions\Servers\Domains\Services\WatchNoticePreferences::read($owner->id,$w->canonical_name);
$pausedId=DB::table('domains_watch_notices')->insertGetId(['notice_key'=>str_repeat('f',64),'scope'=>str_repeat('b',64),'target_kind'=>'watch','watch_id'=>$w->id,'user_id'=>$owner->id,'kind'=>'expiring','family'=>'registry','episode_key'=>str_repeat('9',64),'observation_id'=>3,'state'=>'open','first_seen_at'=>$clock->at,'last_seen_at'=>$clock->at,'data'=>Crypt::encryptString('{}')]);
DB::table('domains_watch_deliveries')->insert(['notice_id'=>$pausedId,'user_id'=>$owner->id,'channel'=>'mail','delivery_key'=>hash('sha256','paused-mail'),'template_key'=>'domains_watch_expiring','state'=>'reserved','reserved_at'=>$clock->at]);
DB::table('services')->where('id',$service->id)->update(['status'=>'suspended']);$sweep();
same('paused',$w->fresh()->state,'A suspended hosting service pauses the watch');
same([['suppressed','discovery_paused']],DB::table('domains_watch_deliveries')->where('notice_id',$pausedId)->get(['state','safe_code'])->map(fn($r)=>[$r->state,$r->safe_code])->all(),'Pause stops the unhanded delivery');
same('suppressed',DB::table('domains_watch_notices')->where('id',$pausedId)->value('state'),'Pause closes the unhanded notice');
same($preferences,Paymenter\Extensions\Servers\Domains\Services\WatchNoticePreferences::read($owner->id,$w->canonical_name),'Pause keeps the saved alert choice');
DB::table('services')->where('id',$service->id)->update(['status'=>'active']);$sweep();
same(['active','suppressed','suppressed'],[$w->fresh()->state,DB::table('domains_watch_notices')->where('id',$pausedId)->value('state'),DB::table('domains_watch_deliveries')->where('notice_id',$pausedId)->value('state')],'Resume replays no paused notice');
same($preferences,Paymenter\Extensions\Servers\Domains\Services\WatchNoticePreferences::read($owner->id,$w->canonical_name),'Resume keeps the saved alert choice');
// Replacing the last hosting source with a managed link keeps the managed reason, so the managed notice can reuse unhanded channels.
$managedId=DB::table('domains_watch_notices')->insertGetId(['notice_key'=>str_repeat('d',64),'scope'=>str_repeat('b',64),'target_kind'=>'watch','watch_id'=>$w->id,'user_id'=>$owner->id,'kind'=>'expiring','family'=>'registry','episode_key'=>str_repeat('e',64),'observation_id'=>2,'state'=>'open','first_seen_at'=>$clock->at,'last_seen_at'=>$clock->at,'data'=>Crypt::encryptString('{}')]);
foreach(['app'=>'reserved','mail'=>'render_failed']as$channel=>$state){DB::table('domains_watch_deliveries')->insert(['notice_id'=>$managedId,'user_id'=>$owner->id,'channel'=>$channel,'delivery_key'=>hash('sha256','managed-'.$channel),'template_key'=>'domains_watch_expiring','state'=>$state,'reserved_at'=>$clock->at]);}
$registrar=App\Models\Server::create(['name'=>'Synthetic registrar','extension'=>'Domains','type'=>'server','enabled'=>true]);
$account=Paymenter\Extensions\Servers\Domains\Models\Account::withVerifiedBindingWrite(fn()=>Paymenter\Extensions\Servers\Domains\Models\Account::create(['server_id'=>$registrar->id,'registrar_key'=>'enom','environment'=>'test','stable_identity'=>'synthetic-notice-binding']));
$managedOrder=App\Models\Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
$managed=App\Models\Service::create(['user_id'=>$owner->id,'order_id'=>$managedOrder->id,'product_id'=>$product->id,'plan_id'=>$plan->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
Paymenter\Extensions\Servers\Domains\Models\Domain::create(['server_id'=>$registrar->id,'registrar_key'=>'enom','account_fingerprint'=>$account->account_fingerprint,'domain'=>$w->canonical_name,'sld'=>explode('.',$w->canonical_name)[0],'tld'=>explode('.',$w->canonical_name,2)[1],'user_id'=>$owner->id,'service_id'=>$managed->id,'status'=>'active']);
$sweep();
same(['managed_link','linked'],[Paymenter\Extensions\Servers\Domains\Models\WatchSource::where('service_id',$service->id)->value('state'),$w->fresh()->state],'The last hosting source becomes a managed link');
same(['registration_managed','registration_managed'],DB::table('domains_watch_deliveries')->where('notice_id',$managedId)->orderBy('id')->pluck('safe_code')->all(),'Unhanded channels keep the managed reason after the last source is replaced');
same(['suppressed','suppressed','accepted','uncertain'],DB::table('domains_watch_deliveries')->where('notice_id',$id)->orderBy('id')->pluck('state')->all(),'Handed deliveries of the older notice are never reopened');
echo "Retirement suppresses pending notices, preserves handed-off evidence and re-adds without replay.\n";$nativeSuccessful=true;
