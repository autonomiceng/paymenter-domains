<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-discovery.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchSource};
$f=discoveryFixture();extract($f);$sweep();same(1,WatchName::count(),'Native discovery prerequisite');$original=WatchSource::where('service_id',$service->id)->sole();
$race=null;
WatchSource::creating(static function($source)use(&$race,$service,$map):void {
    if($source->service_id!==$service->id || !$race){return;}$kind=$race;$race=null;
    if($kind==='status'){DB::table('services')->where('id',$service->id)->update(['status'=>'cancelled']);}
    if($kind==='mapping'){DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>false]);}
    if($kind==='property'){DB::table('properties')->where('model_id',$service->id)->where('key','domain')->update(['value'=>'different.example.com']);}
    if($kind==='suffix'){DB::table('domains_psl_snapshots')->where('state','current')->update(['revision'=>str_repeat('f',64)]);}
});
foreach(['status','mapping','property','suffix']as$kind){
    $before=WatchSource::count();$prior=WatchSource::where('service_id',$service->id)->sole();$audit=DB::table('domains_watch_audit')->count();
    $property->update(['value'=>$kind.'.example.com']);$race=$kind;$work->hint($product->id);$work->tick($clock->at);
    expect($work->failed,'Commit refuses changed '.$kind.' authority');same($before,WatchSource::count(),'Failure retains all prior sources atomically');same($prior->id,WatchSource::where('service_id',$service->id)->value('id'),'No partial retirement on '.$kind);same($audit,DB::table('domains_watch_audit')->count(),'No partial lifecycle audit');
    $sweep();same($kind.'.example.com',WatchSource::where('service_id',$service->id)->value('hostname'),'Next ordinary pass repairs '.$kind);
}
same('active',$service->fresh()->status,'Reentrant cancellation rolled back');same(true,$map->fresh()->enabled,'Reentrant mapping Off rolled back');same([],$dns->calls,'Commit guards perform no public I/O');
echo "Native service, mapping, saved value and suffix changes refuse partial discovery commits.\n";$nativeSuccessful=true;
