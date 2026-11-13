<?php
// Real encrypted WatchName relations and staff facts; no network or full list fixture is needed.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';require __DIR__.'/Support/storefront.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts};
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
$f=noticeFixture();extract($f);\Paymenter\Extensions\Servers\Domains\Services\StaffWatchSchema::install();staffPanel();Auth::setUser(staffUser(array_keys(Staff::permissions())));
$resource=\Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource::class;
$panel=\Filament\Facades\Filament::getCurrentPanel();$panel->resources([$resource]);
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>$resource::registerRoutes($panel));app('router')->getRoutes()->refreshNameLookups();
$w=WatchName::findOrFail($id);$failures=[];
$paused=clone $w;$paused->state='paused';$public=new \Paymenter\Extensions\Servers\Domains\Models\WatchDnsObservation(['check_kind'=>'points','started_at'=>1700000000,'completed_at'=>1700000001,'result'=>'complete','partial'=>false,'valid_until'=>1700000300,'facts'=>['queries'=>[]]]);
$paused->setRelation('points',$public);
$facts=WatchFacts::for($paused,['keys'=>[],'summary'=>null]);
expect(isset($facts['Evidence']['Public DNS']),'Paused empty answers retain Public DNS evidence row');
expect(str_contains((string)$facts['Evidence']['Public DNS']['sub'],'2023-11-14'),'The evidence row keeps its own public observation instant');

// Saved public answers without a template comparison show on DNS records with their own Checked time.
$answered=clone $w;$answered->state='paused';$answered->setRelation('points',new \Paymenter\Extensions\Servers\Domains\Models\WatchDnsObservation(['check_kind'=>'points','started_at'=>1700000000,'completed_at'=>1700000001,'result'=>'complete','partial'=>false,'valid_until'=>1700000300,
    'facts'=>['queries'=>[['name'=>$w->canonical_name,'type'=>'A','records'=>[['value'=>'192.0.2.10']]],['name'=>'other.example','type'=>'A','records'=>[['value'=>'192.0.2.99']]]]]]));
$records=WatchFacts::for($answered,['keys'=>[],'summary'=>null])['Checks']['DNS records'];
same('A @ → 192.0.2.10',$records['value'],'Saved public answers for this name fill DNS records');
$recordsDom=storefrontDom('<div id="records-sub">'.$records['sub'].'</div>');
same([gmdate('c',1700000000)],array_map(fn($n)=>$n->getAttribute('datetime'),iterator_to_array($recordsDom->query('//*[@id="records-sub"]//time'))),'DNS records shows the saved answer time');
$recordsText=trim(preg_replace('/\s+/',' ',$recordsDom->query('//*[@id="records-sub"]')->item(0)->textContent));
same('Historical answers. Checked Nov 14, 2023, 10:13 PM UTC. DNS template comparison is unavailable for these saved records.',$recordsText,'DNS records says Checked before the time and why there is no comparison: '.$recordsText);
$offsetMoment=storefrontDom(view('domains::partials.moment',['at'=>'2026-11-04T01:00:00-08:00'])->render())->query('//time')->item(0);
same(['Nov 4, 2026, 9:00 AM UTC','2026-11-04T01:00:00-08:00'],[$offsetMoment?->textContent,$offsetMoment?->getAttribute('datetime')],'Server time text before the page script is named UTC, whatever offset was saved');

foreach(['active','paused','retired'] as $state){
    $probe=clone $w;$probe->state=$state;$facts=WatchFacts::for($probe,['keys'=>[],'summary'=>null]);$encoded=json_encode($facts);
    $expected=match($state){'paused'=>'Paused','retired'=>'Monitoring stopped; no further checks',default=>'Fills in by itself after the next check'};
    if(!str_contains($encoded,$expected)||$state!=='active'&&str_contains($encoded,'Fills in by itself')){$failures[]='Incorrect missing-fact guidance for '.$state;}
}
$probe=clone $w;$probe->setRelation('work',collect());
if(str_contains(json_encode(WatchFacts::for($probe,['keys'=>[],'summary'=>null])),'Fills in by itself')){$failures[]='Unqueued watch promises a next check';}

// The list uses its real native column renderers, not the detail facts above.
$panel->resources([\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource::class]);
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource::registerRoutes($panel));app('router')->getRoutes()->refreshNameLookups();
$panel->viteTheme([])->theme(new \Illuminate\Support\HtmlString(''));
foreach(['paused'=>'Paused','retired'=>'Stopped']as$state=>$expected){
 \Illuminate\Support\Facades\DB::table('domains_watch_names')->where('id',$id)->update(['state'=>$state]);
 $list=\Livewire\Livewire::test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ListWatches::class);
 $record=$w->fresh();
 same($expected,$list->instance()->getTable()->getColumn('status')->record($record)->getState(),'The row names its monitoring state');
 $rowDom=storefrontDom($list->html());$nativeRow=$rowDom->query('//*[@*[name()="wire:key" and contains(.,".table.records.'.$id.'")]]')->item(0);
 $badges=$nativeRow===null?[]:array_map(fn($n)=>trim($n->textContent),iterator_to_array($rowDom->query('.//*[@data-domains-record="watch-'.$id.'"]',$nativeRow)));
 same([$expected],$badges,'The row status badge itself names its state, independent of the filter options');
 foreach(['registration','delegation.dns','last_check']as$name){$column=$list->instance()->getTable()->getColumn($name)->record($record);
  // Status says Paused or Stopped once per row (ui-r15); the fact cells stay empty instead of repeating it.
  if(filled($column->getState())){$failures[]=$state.' '.$name.' still has Missing or repeats the row state';}
  if(str_contains((string)$column->getDescriptionBelow(),'Fills in by itself')){$failures[]=$state.' '.$name.' promises checks';}
 }
 $html=\Illuminate\Support\Facades\Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$list->instance(),'body'=>$list->html()]);
 $html=preg_replace('~<script\b[^>]*>.*?</script>|<link\b[^>]*>~si','',$html);
 writeRenderArtifact((getenv('R14_RENDER_DIR')?:sys_get_temp_dir()).'/r14-watchlist-'.$state.'.html',str_replace(['</head>','</body>'],['<style>'.file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css').'</style>'.view('domains::partials.staff-styles')->render().'</head>',view('domains::partials.staff-reveal')->render().'</body>'],$html));
}
// The detail page: a paused name keeps its own earlier, empty Public DNS answer beside a later registry check.
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
$detail=fn()=>storefrontDom(\Livewire\Livewire::test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ViewWatch::class,['record'=>$id])->html());
$times=fn(DOMXPath $dom,?DOMNode $in=null)=>array_map(fn($n)=>[$n->getAttribute('datetime'),trim($n->textContent)!==''],iterator_to_array($dom->query($in?'.//time':'//time',$in)));
// A sheet row is a data-piece="row" whose first child is its label.
$publicRow=fn(DOMXPath $dom)=>$dom->query('//*[@data-piece="row"][*[1][normalize-space(.)="Public DNS"]]');
\Illuminate\Support\Facades\DB::table('domains_watch_names')->where('id',$id)->update(['state'=>'active']);noticeRegistry($f,$now);
\Illuminate\Support\Facades\DB::table('domains_watch_names')->where('id',$id)->update(['state'=>'paused']);
$registryAt=gmdate('c',$now);$publicAt=1700000000;
// Never observed: no Public DNS row at all, and nothing borrows the registry time for one.
$never=$detail();
same([0,[[$registryAt,true]]],[$publicRow($never)->length,$times($never)],'A paused name never checked by public DNS shows no Public DNS row');
$w=WatchName::findOrFail($id);
\Illuminate\Support\Facades\DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Models\WatchDnsObservation::create(['watch_id'=>$id,'user_id'=>$uid,'watch_revision'=>$w->revision,'profile_id'=>$profile->id,'profile_revision'=>$profile->revision,'psl_revision'=>$w->psl_revision,'mixed'=>false,'check_kind'=>'points','started_at'=>$publicAt,'completed_at'=>$publicAt+1,'result'=>'complete','partial'=>false,'valid_until'=>$publicAt+300,'facts'=>['version'=>1,'queries'=>[]],'digest'=>str_repeat('b',64),'source_digest'=>str_repeat('c',64)]));
$saved=$detail();$row=$publicRow($saved)->item(0);
same(1,$publicRow($saved)->length,'A paused name with an empty saved answer keeps its Public DNS row');
same(\Paymenter\Extensions\Servers\Domains\Presentation\State::NotReadYet->label(),trim($row===null?'':$saved->query('.//*[@data-piece="value"]/*[1]',$row)->item(0)?->textContent??''),'An empty answer is not shown as a confirmed empty record list');
same([[gmdate('c',$publicAt),true]],$row===null?[]:$times($saved,$row),'The Public DNS row keeps its own earlier time, not the later registry check');
expect(in_array([$registryAt,true],$times($saved),true),'The later registry check still shows on the page');
expect($failures===[],implode('; ',$failures));$nativeSuccessful=true;echo "Watch missing facts respect stopped, paused and queued work.\n";
