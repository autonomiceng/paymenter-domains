<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-discovery.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchSource};
$f=discoveryFixture();extract($f);$sweep();same(1,WatchName::count(),'Runtime discovery enrolls saved primary scalar');
foreach(['https://www.example.com','a@example.com','example.com:443','example.com/path','127.0.0.1','*.example.com','a.com,b.com',"example.com\n",'example.com..','com','unknown.test','tenant.github.io','github.io','xn--bcher-kva.com','bücher.com','["example.com"]']as$value){
    $property->update(['value'=>$value]);$sweep();$source=WatchSource::where('service_id',$service->id)->sole();same('held',$source->state,'Unsafe input is held: '.json_encode($value));same(null,$source->hostname,'Unsafe property never becomes a query hostname');same(null,$source->watch_id,'Unsafe input has no external work target');
}
$property->update(['value'=>'WWW.Example.COM.']);$sweep();same('www.example.com',WatchSource::where('service_id',$service->id)->value('hostname'),'Case and one root dot normalized');
$duplicate=$service->properties()->create(['key'=>'domain','value'=>'other.com']);$sweep();same('held',WatchSource::where('service_id',$service->id)->value('state'),'Duplicate primary values cannot be split or guessed');$duplicate->delete();
foreach(['a.co.uk'=>'a.co.uk','sub.www.ck'=>'www.ck','a.b.ck'=>'a.b.ck']as$host=>$name){$property->update(['value'=>$host]);$sweep();same($name,WatchSource::where('service_id',$service->id)->value('registrable_name'),'ICANN wildcard and exception: '.$host);}
$property->update(['value'=>'www.example.com']);$sweep();$w=WatchName::where('canonical_name','example.com')->sole();
DB::table('domains_psl_snapshots')->where('state','current')->update(['validated_at'=>$clock->at-91*86400]);$sweep();same('active',$w->fresh()->state,'Aged PSL does not stop unchanged known checks');
$extra=discoveryService($f,'new.com');$sweep();same('held',WatchSource::where('service_id',$extra->id)->value('state'),'Stale PSL prevents new discovery');
DB::table('domains_psl_snapshots')->where('state','current')->update(['validated_at'=>$clock->at]);$sweep();same('new.com',WatchSource::where('service_id',$extra->id)->value('registrable_name'),'Revalidated list repairs a held source');
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>false,'revision'=>2]);$sweep();same(0,WatchSource::where('state','watch')->count(),'Disabled mapping retires its retained sources');same(0,WatchName::where('state','active')->count(),'No live sources means no active watch');
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>true,'revision'=>3]);$sweep();same(2,WatchSource::where('state','watch')->count(),'Re-enabled mapping re-adds hosting domains');same([],$dns->calls,'Input parsing is local only');
echo "Exact scalar, normalization, PSL, held input, mapping Off and return passed.\n";$nativeSuccessful=true;
