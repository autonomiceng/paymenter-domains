<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{ManagedPublicObservation,WatchSource};
extract(managedFixture(skipA3:true));$id=(int)$d->id;$uid=(int)$owner->id;
DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'paused']);
DB::table('services')->where('id',$host->id)->update(['status'=>'suspended']);
$fake->answers['example.com|NS']=managedNs();
expect($signals->observe($id,$uid,'delegation'),'Paused predecessor and suspended hosting do not disable managed apex');
same('retired',$source->fresh()->state,'Invalid predecessor host archived during atomic relink');same('linked',$watch->fresh()->state,'Paused predecessor still becomes tombstone');
same(['example.com|NS'],$fake->calls,'Holding apex independent of hosting suspension');
DB::table('services')->where('id',$host->id)->update(['status'=>'active']);
$link=DB::transaction(fn()=>WatchSource::create(['user_id'=>$uid,'service_id'=>$host->id,'map_id'=>$map->id,'map_revision'=>1,
    'hostname'=>'www.example.com','registrable_name'=>'example.com','managed_domain_id'=>$id,'state'=>'managed_link','source_digest'=>str_repeat('c',64),'last_seen_at'=>$time->now]));
$property=$host->properties()->where('key','domain')->sole();

foreach([
    ['services',$host->id,['status'=>'suspended'],['status'=>'active']],
    ['services',$host->id,['status'=>'cancelled'],['status'=>'active']],
    ['services',$host->id,['user_id'=>$other->id],['user_id'=>$uid]],
    ['domains_watch_product_maps',$map->id,['enabled'=>false],['enabled'=>true]],
    ['domains_watch_product_maps',$map->id,['revision'=>2],['revision'=>1]],
    ['properties',$property->id,['value'=>'changed.example.com'],['value'=>'www.example.com']],
]as[$table,$key,$changes,$restore]) {
    DB::table($table)->where('id',$key)->update($changes);$time->now+=60;managedDue($id,$time->now);$before=count($fake->calls);
    expect($signals->observe($id,$uid,'delegation'),'Hosting drift never starves holding apex: '.$table);
    same(['example.com|NS'],array_slice($fake->calls,$before),'Only apex queried after source drift');
    same('complete',ManagedPublicObservation::orderByDesc('id')->first()->result,'Independent apex fact stays complete');
    DB::table($table)->where('id',$key)->update($restore);
}
$nativeSuccessful=true;echo "Suspended predecessors and changed managed hosting sources cannot starve independent apex checks.\n";
