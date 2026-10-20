<?php
// Staff watch findings, attention filter and customer identity over local synthetic evidence. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';require __DIR__.'/Support/storefront.php';
use Illuminate\Support\Facades\{Auth,Crypt,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use App\Admin\Resources\UserResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
use Paymenter\Extensions\Servers\Domains\Dns\DnsReading;
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,StaffWatchWrites as Writes,WatchNoticePreferences};
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';$f=noticeFixture();extract($f);\Paymenter\Extensions\Servers\Domains\Services\StaffWatchSchema::install();staffPanel();$panel=Filament::getCurrentPanel();$resources=[WatchResource::class,UserResource::class];$panel->resources($resources);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resources,$panel){foreach($resources as$r){$r::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$owner->update(['first_name'=>'Avery','last_name'=>'Customer','email'=>'avery@example.test']);
$viewer=staffUser([Staff::VIEW,Writes::VIEW]);$users=staffUser([Staff::VIEW,Writes::VIEW,'admin.users.view']);$editor=staffUser([Staff::VIEW,Writes::VIEW,'admin.users.view','admin.users.update']);
$clone=function(string $table,int $from,array $changes=[]):int{$row=(array)DB::table($table)->where('id',$from)->first();unset($row['id']);return (int)DB::table($table)->insertGetId([...$row,...$changes]);};
$sourceId=(int)DB::table('domains_watch_sources')->where('watch_id',$id)->value('id');
$add=function(string $name)use($clone,$id,$service,$order,$property,$sourceId):int{
    $wid=$clone('domains_watch_names',$id,['canonical_name'=>$name]);$sid=$clone('services',$service->id,['order_id'=>$clone('orders',$order->id)]);
    $clone('properties',$property->id,['model_id'=>$sid,'value'=>'www.'.$name]);$clone('domains_watch_sources',$sourceId,['watch_id'=>$wid,'service_id'=>$sid,'hostname'=>'www.'.$name,'registrable_name'=>$name]);
    foreach(DB::table('domains_watch_work')->where('watch_id',$id)->pluck('id') as $job){$clone('domains_watch_work',(int)$job,['watch_id'=>$wid]);}
    return $wid;
};
$at=time()-120;
$read=function(int $wid,string $kind,DnsReading $reading)use($dns,$uid,$at):void{DB::table('domains_watch_work')->where('watch_id',$wid)->where('kind',$kind)->update(['due_at'=>$at,'backoff_until'=>null,'claim'=>null,'lease_until'=>null,'safe_code'=>null]);$t=$dns->begin($wid,$uid,$kind,$at);expect($t!==null,'Synthetic '.$kind.' ticket');expect($dns->finish($t,$reading,$at+1),'Synthetic '.$kind.' reading');};
$expiry=function(int $wid,int $days)use($registry,$uid,$at):void{DB::table('domains_watch_work')->where('watch_id',$wid)->where('kind','expiry')->update(['due_at'=>$at,'backoff_until'=>null,'claim'=>null,'lease_until'=>null,'safe_code'=>null]);$t=$registry->begin($wid,$uid,$at);expect($t!==null,'Synthetic registry ticket');
    expect($registry->finish($t,['result'=>'facts','reason'=>'facts','safe_code'=>null,'expiry'=>gmdate('Y-m-d',$at+$days*86400),'precision'=>'date','source'=>null,'status'=>[]],str_repeat('c',64),$at+1),'Synthetic registry facts');};
$checked=function(int $wid,string $ns,int $days)use($read,$expiry):void{$read($wid,'delegation',new DnsReading('delegation',$ns));$read($wid,'points',new DnsReading('points',website:true,email:true));$expiry($wid,$days);};
DB::table('domains_watch_names')->where('id',$id)->update(['canonical_name'=>'healthy.example']);DB::table('domains_watch_sources')->where('id',$sourceId)->update(['registrable_name'=>'healthy.example','hostname'=>'www.healthy.example']);$property->update(['value'=>'www.healthy.example']);
$w=['healthy'=>$id];foreach(['ns-changed','ns-pending','expired','expiring','stale','not-checked','paused'] as $name){$w[$name]=$add($name.'.example');}
$checked($w['healthy'],'platform',200);$checked($w['ns-changed'],'external',200);$checked($w['ns-pending'],'external',200);$checked($w['expired'],'platform',-2);$checked($w['expiring'],'platform',10);$checked($w['stale'],'platform',200);$checked($w['paused'],'platform',200);
DB::table('domains_watch_registry')->where('watch_id',$w['stale'])->update(['valid_until'=>$at+5]);DB::table('domains_watch_names')->where('id',$w['paused'])->update(['state'=>'paused']);
// The portal's confirmed nameserver episode, as written by the notice planner.
DB::table('domains_watch_notice_state')->insert(['scope'=>WatchNoticePreferences::scope($uid,'ns-changed.example'),'user_id'=>$uid,'data'=>Crypt::encryptString(json_encode(['target'=>['watch',$w['ns-changed']],'portal'=>['dns'=>['ns_changed']],'dns'=>['episode'=>$at]])),'updated_at'=>$at]);
$expected=['healthy'=>[],'ns-changed'=>['watch.ns_changed'],'ns-pending'=>['watch.ns_pending'],'expired'=>['watch.expired'],'expiring'=>['staff.expiring'],'stale'=>['watch.stale'],'not-checked'=>['watch.not_checked'],'paused'=>['staff.stopped']];
$writes=fn()=>DB::table('domains_watch_work')->get()->toJson().DB::table('domains_watch_observations')->count().DB::table('domains_watch_registry')->count().DB::table('domains_watch_notice_state')->count();$before=$writes();
// Findings per row on the real native list; staff keys agree with the customer summary keys.
Auth::setUser($viewer);$page=Livewire::actingAs($viewer)->test(ListWatches::class);$list=$page->instance();$records=$list->getTableRecords()->keyBy('id');$column=$list->getTable()->getColumn('findings');$reader=new DomainSummaries();
foreach($expected as $name=>$keys){$record=$records[$w[$name]];same($keys,$list->watchRow($record)['findings'],'Staff findings for '.$name);same(WatchFindings::labels($keys),(array)($column->record($record)->getState()??[]),'Rendered findings for '.$name);
    if($name!=='paused'){same(array_values(array_filter($keys,fn($k)=>!str_starts_with($k,'staff.'))),array_values(array_intersect(array_keys(WatchFindings::LABELS),$reader->one($owner,'watch',$w[$name])->stateKeys)),'Customer summary agrees for '.$name);}}
$page->assertSee('Nameservers changed')->assertSee('Registry expiry reached')->assertSee('Checks out of date')->assertSee('Not checked yet')->assertSee('No findings')->assertDontSee('Pointer check');
foreach(['Nameservers changed'=>'warning','Registry expiry reached'=>'warning','Nameserver change unconfirmed'=>'info','Registry expiry within 30 days'=>'warning','Not checked yet'=>'gray','Registry: redemption or pending delete'=>'danger','Not evaluated · checks stopped'=>'gray'] as $label=>$color){same($color,WatchFindings::color($label),'Tone for '.$label);}
// The attention filter selects exactly the rows with actionable findings.
$attention=array_map(fn($n)=>WatchName::find($w[$n]),['ns-changed','ns-pending','expired','expiring','stale','not-checked']);
Livewire::actingAs($viewer)->test(ListWatches::class)->filterTable('attention')->assertCanSeeTableRecords($attention)->assertCanNotSeeTableRecords([WatchName::find($w['healthy']),WatchName::find($w['paused'])])->assertCountTableRecords(count($attention));
// Customer identity needs the native user permissions, checked fresh on every request.
$customer=fn($user)=>Livewire::actingAs($user)->test(ListWatches::class)->instance()->watchRow(WatchName::find($w['healthy']))['customer'];
same(['label'=>'Customer #'.$uid,'email'=>null,'url'=>null],$customer($viewer),'Neutral identifier without user permission');Livewire::actingAs($viewer)->test(ListWatches::class)->assertDontSee('avery@example.test')->assertDontSee('Avery Customer');
same(['label'=>'Avery Customer','email'=>'avery@example.test','url'=>null],$customer($users),'Name and email with view permission; no link to a page the role cannot open');
same(['label'=>'Avery Customer','email'=>'avery@example.test','url'=>UserResource::getUrl('edit',['record'=>$uid])],$customer($editor),'Native user page link with update permission');
Livewire::actingAs($editor)->test(ListWatches::class)->assertSee('avery@example.test')->assertSeeHtml('href="'.e(UserResource::getUrl('edit',['record'=>$uid])).'"');
$editor->role->update(['permissions'=>[Staff::VIEW,Writes::VIEW]]);same(['label'=>'Customer #'.$uid,'email'=>null,'url'=>null],$customer($editor),'Revoked user permission falls back');$editor->role->update(['permissions'=>[Staff::VIEW,Writes::VIEW,'admin.users.view','admin.users.update']]);
// Detail shows the same findings first, with the same customer rules.
Livewire::actingAs($viewer)->test(ViewWatch::class,['record'=>$w['ns-changed']])->assertSeeInOrder(['Findings','Nameservers changed','Saved domain checks'])->assertSee('Customer #'.$uid)->assertDontSee('avery@example.test');
Livewire::actingAs($viewer)->test(ViewWatch::class,['record'=>$w['healthy']])->assertSeeInOrder(['Findings','No findings','Saved domain checks']);
Livewire::actingAs($editor)->test(ViewWatch::class,['record'=>$w['expired']])->assertSeeInOrder(['Registry expiry reached','Avery Customer','avery@example.test'])->assertSeeHtml('href="'.e(UserResource::getUrl('edit',['record'=>$uid])).'"');
Livewire::actingAs($users)->test(ViewWatch::class,['record'=>$w['expired']])->assertSee('avery@example.test')->assertDontSeeHtml('/admin/users/'.$uid.'/edit');
same($before,$writes(),'Staff list, filter and detail GETs write nothing');
// 25 rows cost the same as one row for a customer; extra customers cost one owner batch each, never per row.
for($i=count($w);$i<25;$i++){$add('bulk'.$i.'.example');}same(25,WatchName::count(),'Twenty-five synthetic watches');
$count=function(callable $run):int{DB::flushQueryLog();DB::enableQueryLog();$run();$n=count(DB::getQueryLog());DB::disableQueryLog();return $n;};
$one=$count(fn()=>Livewire::actingAs($editor)->test(ListWatches::class)->searchTable('healthy.example')->assertCountTableRecords(1));
$many=$count(fn()=>Livewire::actingAs($editor)->test(ListWatches::class)->searchTable('example')->assertCountTableRecords(25));
same($one,$many,'Same-customer query count does not grow per row ('.$one.')');
$batch=$count(fn()=>$reader->batch($owner,[],[$id]));$page25=$count(fn()=>Livewire::actingAs($editor)->test(ListWatches::class)->assertCountTableRecords(25));$others=[];for($i=0;$i<4;$i++){$others[]=staffUser([])->id;}
foreach(WatchName::where('canonical_name','like','bulk%')->orderBy('id')->get()->values() as $i=>$bulk){DB::table('domains_watch_names')->where('id',$bulk->id)->update(['user_id'=>$others[$i%4]]);}
// One mounted render each: four more customers add at most one owner batch each.
$spread=$count(fn()=>Livewire::actingAs($editor)->test(ListWatches::class)->assertCountTableRecords(25));
$filtered=$count(fn()=>Livewire::actingAs($editor)->test(ListWatches::class)->filterTable('attention')->assertCountTableRecords(23));expect($filtered<=2*$spread+5*$batch+10,'Filtering is one more render plus one owner batch per customer, evaluated once per request ('.$filtered.' <= 2 x '.$spread.' + 5 x '.$batch.' + 10)');
expect($spread<=$page25+4*$batch,'Five customers cost at most one batch each ('.$spread.' <= '.$page25.' + 4 x '.$batch.')');expect($spread<=$page25+4*40,'Bounded page query count');
$nativeSuccessful=true;echo "Staff watch findings, attention filter, permission-checked customer identity and bounded queries passed (queries: 1 row {$one}, 25 rows {$many}, one render {$page25}, five customers {$spread}, filtered {$filtered}, owner batch {$batch}).\n";
