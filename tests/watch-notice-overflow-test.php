<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchSource,WatchNotice};
use Paymenter\Extensions\Servers\Domains\Services\{WatchNoticeTarget,WatchDnsChecks};
$f=noticeFixture();extract($f);
for($i=0;$i<100;$i++) {
    $extra=$service->replicate();$extra->save();$extra->properties()->create(['key'=>'domain','name'=>'Domain','value'=>'www.example.com']);
    DB::transaction(fn()=>WatchSource::create(['user_id'=>$uid,'service_id'=>$extra->id,'map_id'=>$map->id,'map_revision'=>1,
        'hostname'=>'www.example.com','registrable_name'=>'example.com','watch_id'=>$id,'state'=>'watch','source_digest'=>str_repeat('b',64),'last_seen_at'=>$now]));
}
foreach([$now,$now+900] as $at) {
    DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','delegation')->update(['source_cursor'=>0]);noticeDns($f,$at);
}
$planner->plan('watch',$id,$uid,$now+902);$n=WatchNotice::where('kind','ns_changed')->sole();
$claim=$delivery->reserve($n->id,'mail',$now+903);expect($claim!==null,'Overflow confirmed drift reserves');
$observation=\Paymenter\Extensions\Servers\Domains\Models\WatchDnsObservation::latest('id')->first();
$before=(new WatchDnsChecks())->registryTarget($id,$uid,false,$observation->source_cursor)['stamp'];
// Edit only an omitted cohort, leaving the observed cohort's stamp unchanged.
$p=$product->replicate();$p->slug='notice-omitted-conflict';$p->save();
$conflict=DB::table('domains_watch_product_maps')->insertGetId(['product_id'=>$p->id,'edited_by'=>$uid,'reason'=>'Synthetic omitted conflict',
    'property_key'=>'domain','enabled'=>true,'expected_ns_profile_id'=>$profile->id+100,'revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
$omitted=WatchSource::where('watch_id',$id)->orderByDesc('id')->first();
DB::table('domains_watch_sources')->where('id',$omitted->id)->update(['map_id'=>$conflict]);
same($before,(new WatchDnsChecks())->registryTarget($id,$uid,false,$observation->source_cursor)['stamp'],'Omitted conflict does not change observed cohort stamp');
DB::transaction(fn()=>same(null,WatchNoticeTarget::capture('watch',$id,$uid,$now+904)['delegation'],'Watch-wide profile conflict refuses proof'));
expect(!$delivery->handoff($claim,$now+904),'Omitted conflicting profile suppresses reserved mail');
same('suppressed',DB::table('domains_watch_deliveries')->find($claim)->state,'Conflicting profile is definitely unsent');
$nativeSuccessful=true;echo "Omitted cohort profile conflicts refuse current notice proof and reserved handoff.\n";
