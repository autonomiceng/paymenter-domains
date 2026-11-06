<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native staff monitoring over synthetic local evidence.
declare(strict_types=1);
require __DIR__.'/Support/staff-monitoring.php';
use Illuminate\Support\Facades\{Auth,Crypt,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration as ListTemplates,ViewConfiguration as ViewTemplate};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
extract($checked());
// Expiry indicators must describe the same pre-expiry candidates as the bounded historical scan.
DB::beginTransaction();
$watchSeed=WatchName::findOrFail($id)->getRawOriginal();unset($watchSeed['id']);
$observation=(array)DB::table('domains_watch_registry')->where('watch_id',$id)->where('result','facts')->first();
expect(isset($observation['id']),'Expiry indicator fixture has a real facts observation');unset($observation['id']);
$registryFacts=json_decode(Crypt::decryptString($observation['facts']),true,flags:JSON_THROW_ON_ERROR);
$near=[...$observation,'facts'=>Crypt::encryptString(json_encode([...$registryFacts,'expiry'=>gmdate('Y-m-d',time()+3*86400)],JSON_THROW_ON_ERROR))];
$far=[...$observation,'facts'=>Crypt::encryptString(json_encode([...$registryFacts,'expiry'=>gmdate('Y-m-d',time()+200*86400)],JSON_THROW_ON_ERROR))];
$unknown=[...$observation,'result'=>'unknown','reason'=>'endpoint_failed','valid_until'=>null,
    'facts'=>Crypt::encryptString(json_encode(['result'=>'unknown','expiry'=>null],JSON_THROW_ON_ERROR))];
DB::table('domains_watch_registry')->delete();DB::table('domains_watch_names')->delete();
$linkedCandidate=$makeLinked('expiry-linked.example');$candidateIds=[(int)$linkedCandidate->id];
DB::table('domains_watch_registry')->insert([...$near,'watch_id'=>$linkedCandidate->id]);
$watchRows=[];$factRows=[];$first=(int)$linkedCandidate->id+1;
for($i=0;$i<999;$i++){
    $wid=$first+$i;$candidateIds[]=$wid;$state=match($i){0=>'active',1=>'retired',default=>'paused'};
    $watchRows[]=[...$watchSeed,'id'=>$wid,'canonical_name'=>'expiry-candidate-'.$i.'.example','state'=>$state];
    $factRows[]=[...($i<3?$near:$far),'watch_id'=>$wid];
}
foreach(array_chunk($watchRows,100) as $rows){DB::table('domains_watch_names')->insert($rows);}
foreach(array_chunk($factRows,100) as $rows){DB::table('domains_watch_registry')->insert($rows);}
$nearIds=array_slice($candidateIds,0,4);$indicatorErrors=[];
$checkExpiry=static function(string $case,array $filters,array $expectedIds,bool $capped)use($manager,$applyFilters,$filter,&$indicatorErrors):void{
    $page=$applyFilters(Livewire::actingAs($manager)->test(ListWatches::class),[...$filters,'expiry'=>'30']);
    $instance=$page->instance();
    // Livewire drops protected scan state between requests. Read the indicator before resolving records.
    if(property_exists($instance,'expiryCapped')){(new ReflectionProperty(ListWatches::class,'expiryCapped'))->setValue($instance,null);}
    if(property_exists($instance,'expiryScans')){(new ReflectionProperty(ListWatches::class,'expiryScans'))->setValue($instance,[]);}
    DB::flushQueryLog();DB::enableQueryLog();
    $indicators=$filter($page,'expiry')->getIndicators();expect(count($indicators)===1,'One actual expiry indicator for '.$case);
    $actual=(string)$indicators[0]->getLabel();$expected='Expires within 30 days'.($capped?' (first 1,000 domains)':'');
    $instance->getTableRecords();
    $actualIds=$instance->getFilteredTableQuery()->orderBy('id')->pluck('id')->all();
    $instance->getFilteredTableQuery();
    $scans=array_filter(DB::getQueryLog(),static fn($q)=>str_contains($q['query'],'max(id)')&&str_contains($q['query'],'domains_watch_registry'));
    DB::disableQueryLog();
    same(1,count($scans),'Indicator and repeated record queries share one expiry scan: '.$case);
    echo 'Expiry indicator probe '.$case.': '.json_encode(['expected'=>$expected,'actual'=>$actual,'matches'=>count($actualIds)],JSON_THROW_ON_ERROR)."\n";
    if($actual!==$expected){$indicatorErrors[]=$case.': expected '.$expected.'; got '.$actual;}
    sort($expectedIds);sort($actualIds);if($actualIds!==$expectedIds){$indicatorErrors[]=$case.': exact expiry matches differ (expected '.json_encode($expectedIds).'; got '.json_encode($actualIds).')';}
};
$checkExpiry('1,000 facts-bearing watches in all states',[],$nearIds,false);
// Repeated history counts once; the newest facts row supplies the expiry, even after a non-facts result.
for($i=0;$i<12;$i++){DB::table('domains_watch_registry')->insert([...$near,'watch_id'=>$linkedCandidate->id]);}
DB::table('domains_watch_registry')->insert([...$far,'watch_id'=>$linkedCandidate->id]);
$checkExpiry('repeated facts use the latest expiry',[],array_slice($nearIds,1),false);
DB::table('domains_watch_registry')->insert([...$near,'watch_id'=>$linkedCandidate->id]);
DB::table('domains_watch_registry')->insert([...$unknown,'watch_id'=>$linkedCandidate->id]);
$checkExpiry('new facts cycle survives a later non-facts result',[],$nearIds,false);
$orphanRows=[];for($i=0;$i<1001;$i++){$orphanRows[]=[...$near,'watch_id'=>9000000+$i];}
foreach(array_chunk($orphanRows,100) as $rows){DB::table('domains_watch_registry')->insert($rows);}
$checkExpiry('orphan facts do not consume the scan',[],$nearIds,false);
$nonfactRows=[];$nonfactObservations=[];$nonfactIds=[];
for($i=0;$i<1001;$i++){
    $wid=$first+999+$i;$nonfactIds[]=$wid;
    $nonfactRows[]=[...$watchSeed,'id'=>$wid,'canonical_name'=>'no-facts-'.$i.'.example','state'=>'active'];
    $nonfactObservations[]=[...$unknown,'watch_id'=>$wid];
}
foreach(array_chunk($nonfactRows,100) as $rows){DB::table('domains_watch_names')->insert($rows);}
foreach(array_chunk($nonfactObservations,100) as $rows){DB::table('domains_watch_registry')->insert($rows);}
$checkExpiry('1,001 active non-facts watches do not add candidates',[],$nearIds,false);
$overflow=DB::table('domains_watch_names')->insertGetId([...$watchSeed,'user_id'=>$other->id,'canonical_name'=>'overflow-expiry.example','state'=>'retired']);
DB::table('domains_watch_registry')->insert([...$near,'watch_id'=>$overflow,'user_id'=>$other->id]);
$checkExpiry('1,001 distinct candidates exceed the scan',[],$nearIds,true);
$checkExpiry('customer narrows 1,001 candidates to exactly 1,000',['customer'=>['customer'=>(string)$uid]],$nearIds,false);
$checkExpiry('other customer scans its late candidate',['customer'=>['customer'=>(string)$other->id]],[(int)$overflow],false);
$checkExpiry('paused status narrows the candidate set',['status'=>'paused'],[$nearIds[3]],false);
$checkExpiry('customer and stopped status keep linked and retired',['customer'=>['customer'=>(string)$uid],'status'=>'stopped'],[$nearIds[0],$nearIds[2]],false);
$checkExpiry('other customer and stopped status scan the late candidate',['customer'=>['customer'=>(string)$other->id],'status'=>'stopped'],[(int)$overflow],false);
$checkExpiry('empty customer and status intersection',['customer'=>['customer'=>(string)$other->id],'status'=>'paused'],[],false);
// A warning is still needed when the extra candidates are stopped, with few active watches globally.
DB::table('domains_watch_names')->whereIn('id',$nonfactIds)->update(['state'=>'retired']);
$checkExpiry('few active watches still truncate historical facts',[],$nearIds,true);
// Reusing one component must still distinguish intervals and incoming filter values.
$cached=new ListWatches;$scope=static fn(int $owner)=>WatchName::where('user_id',$owner);
foreach([7,30,90] as $days){
    same([(int)$overflow],$cached->expiryQuery($scope((int)$other->id),$days)->pluck('id')->all(),'Expiry cache keeps interval '.$days.' scoped to the requested customer');
}
$ownMatches=$cached->expiryQuery($scope((int)$uid),30)->orderBy('id')->pluck('id')->all();
same($nearIds,$ownMatches,'Different customer bindings retain their own expiry matches: '.json_encode($ownMatches));
same([], $cached->expiryQuery($scope(-1),30)->pluck('id')->all(),'Different customer bindings cannot borrow cached matches');
$intervalSeed=(int)$linkedCandidate->id;
DB::table('domains_watch_registry')->insert([...$near,'watch_id'=>$intervalSeed,'facts'=>Crypt::encryptString(json_encode([...$registryFacts,'expiry'=>gmdate('Y-m-d',time()+60*86400)],JSON_THROW_ON_ERROR))]);
$fresh=new ListWatches;$only=static fn()=>WatchName::whereKey($intervalSeed);
same([],$fresh->expiryQuery($only(),7)->pluck('id')->all(),'Seven-day scan excludes a sixty-day expiry');
same([],$fresh->expiryQuery($only(),30)->pluck('id')->all(),'Thirty-day scan excludes a sixty-day expiry');
same([$intervalSeed],$fresh->expiryQuery($only(),90)->pluck('id')->all(),'Ninety-day scan does not reuse a shorter interval');
DB::table('domains_watch_registry')->insert([...$near,'watch_id'=>$intervalSeed]);
same([$intervalSeed],(new ListWatches)->expiryQuery($only(),7)->pluck('id')->all(),'A new request rechecks changed expiry facts');
DB::rollBack();
expect($indicatorErrors===[],"Expiry indicator regressions:\n".implode("\n",$indicatorErrors));

$nativeSuccessful=true;echo "Staff monitoring bounded expiry filters passed\n";
