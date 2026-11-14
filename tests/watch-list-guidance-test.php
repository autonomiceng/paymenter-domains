<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native list columns must describe the saved monitoring state.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';require __DIR__.'/Support/storefront.php';
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';
use Illuminate\Support\Facades\{DB,Route};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ListWatches;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
$f=noticeFixture();extract($f);staffPanel();$panel=\Filament\Facades\Filament::getCurrentPanel();$resources=[WatchResource::class,RoutingProfileResource::class];$panel->resources($resources);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resources,$panel){foreach($resources as $resource){$resource::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$actor=staffUser([Staff::VIEW,\Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::VIEW]);$errors=[];
// A native table is necessary: the facts helper was correct while these columns still promised another check.
// Paused and stopped names leave these cells empty: Status names the state once. An active one reads Not read yet on one line.
foreach(['paused'=>['paused','Paused','',''],'retired'=>['stopped','Stopped','',''],'active'=>['monitoring','Monitored','Not read yet','Not read yet']]as$state=>[$filter,$label,$word,$cause]){
 DB::table('domains_watch_names')->where('id',$id)->update(['state'=>$state]);DB::table('domains_watch_work')->where('watch_id',$id)->delete();$record=WatchName::find($id);
 $list=Livewire\Livewire::actingAs($actor)->test(ListWatches::class)->filterTable('status',$filter)->assertCanSeeTableRecords([$record]);
 foreach(['registration','delegation.dns']as$name){$column=$list->instance()->getTable()->getColumn($name)->record($record);$shown=(string)$column->getState();if($shown!==$word||trim(html_entity_decode(strip_tags((string)($shown===''?'':$column->formatState($shown)))))!==$cause){$errors[]=$state.' '.$name.' list guidance promises the wrong next step';}}
 if($list->instance()->getTable()->getColumn('status')->record($record)->getState()!==$label){$errors[]=$state.' status does not name the state';}
 unset($list);gc_collect_cycles();
}
expect($errors===[],implode('; ',$errors));$nativeSuccessful=true;echo "Native monitoring list guidance passed\n";
