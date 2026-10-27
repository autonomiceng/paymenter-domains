<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchRegistryObservation};
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,StaffWatchWrites};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;

extract(noticeFixture());
require dirname(__DIR__).'/Domains/routes/owned.php';
app('router')->getRoutes()->refreshNameLookups();
Auth::setUser(staffUser([Staff::VIEW,StaffWatchWrites::VIEW]));
$reader=new DomainSummaries();$revision=str_repeat('c',64);
$good=['result'=>'facts','reason'=>'facts','safe_code'=>null,'expiry'=>'2028-01-01','precision'=>'date','source'=>'https://rdap.example.com','status'=>[]];
$otherWatch=DB::transaction(fn()=>WatchName::create(['user_id'=>$uid,'canonical_name'=>'other.example.com','psl_revision'=>str_repeat('a',64),'state'=>'active']));
$seed=static function(int $watchId,int $userId,array $facts,int $at)use($revision):void {
    DB::transaction(fn()=>WatchRegistryObservation::create(['watch_id'=>$watchId,'user_id'=>$userId,'stamp'=>str_repeat('d',64),
        'source_cursor'=>0,'bootstrap_revision'=>$revision,'started_at'=>$at,'completed_at'=>$at+1,'valid_until'=>$at+172800,
        'result'=>'facts','reason'=>'facts','safe_code'=>null,'facts'=>$facts]));
};
foreach ([true,false,1,'true',null] as $case=>$marker) {
    $at=$now+$case*1000;
    $seed($id,$uid,$good+($marker===null?[]:['offline_demo'=>$marker])+['old_seed_only'=>'discard'],$at);
    // Newer observations from another owner or another watch must not supply the marker.
    $seed($id,(int)$other->id,$good+['offline_demo'=>$marker!==true],$at);
    $seed((int)$otherWatch->id,$uid,$good+['offline_demo'=>$marker!==true],$at);
    foreach (['facts','endpoint_failed','no_coverage'] as $step=>$reason) {
        // Each path runs twice so an appended result must preserve the marker on the next cycle too.
        foreach ([1,2] as $cycle) {
            $time=$at+10+$step*100+$cycle*10;
            DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','expiry')->update(['due_at'=>$time,'backoff_until'=>null]);
            $ticket=$registry->begin($id,$uid,$time);
            expect($ticket!==null,'Refresh is claimed for marker case '.$case);
            $result=$reason==='facts'?$good:array_replace($good,['result'=>'unknown','reason'=>$reason,'safe_code'=>$reason,'expiry'=>null,'precision'=>null]);
            // Observer results cannot promote ordinary watches to demo records.
            if ($marker!==true) { $result['offline_demo']=true; }
            expect($registry->finish($ticket,$result,$revision,$time+1),'Registry refresh appends '.$reason.' cycle '.$cycle);
            $latest=WatchRegistryObservation::where('watch_id',$id)->where('user_id',$uid)->latest('id')->firstOrFail();
            same($marker===true,array_key_exists('offline_demo',$latest->facts),'Only a strict true marker is copied');
            same($marker===true,$latest->facts['offline_demo']??false,'Only strict true from the same owner and watch survives');
            expect(!array_key_exists('old_seed_only',$latest->facts),'Previous registry facts are not copied');
            same($reason,$latest->facts['reason'],'New registry result is retained');
            same($marker===true,DemoData::is($watch->fresh()),'Record tag survives '.$reason.' cycle '.$cycle);
            $summary=$reader->one($owner,'watch',$id,$time+2);
            expect($summary!==null,'Refresh produces an owned summary');
            same($marker===true,$summary->demo,'Summary tag survives '.$reason.' cycle '.$cycle);
            same($marker===true,$reader->staffWatches([$id],$time+2)['watch:'.$id]->demo,'Staff summary keeps the same marker');
        }
    }
}
// Quota deferrals and deadline refusals must not append a marker or an observation.
foreach (['deferred','deadline'] as $step=>$reason) {
    $time=$now+10000+$step*100;
    DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','expiry')->update(['due_at'=>$time,'backoff_until'=>null]);
    $ticket=$registry->begin($id,$uid,$time);expect($ticket!==null,'No-append path is claimed');
    $before=WatchRegistryObservation::count();
    expect(!$registry->finish($ticket,$reason==='deferred'?['reason'=>'deferred']:$good,$revision,$time+($reason==='deadline'?45:1)),'No-append path refuses '.$reason);
    same($before,WatchRegistryObservation::count(),'No observation is appended for '.$reason);
}
$nativeSuccessful=true;echo "Demo registry markers survive repeated successful and failed refreshes without crossing owners or watches.\n";
