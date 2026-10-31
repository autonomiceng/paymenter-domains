<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchSource,WatchNotice};
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
$nativeSuccessful=true;echo "Confirmed drift past the source window reserves mail.\n";
