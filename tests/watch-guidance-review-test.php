<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real watch work and staff fact projections.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';require __DIR__.'/Support/storefront.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';
$f=noticeFixture();extract($f);staffPanel();$panel=\Filament\Facades\Filament::getCurrentPanel();
$resources=[\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource::class,\Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource::class];$panel->resources($resources);
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resources,$panel){foreach($resources as $resource){$resource::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
Auth::setUser(staffUser([Staff::VIEW,\Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::VIEW]));$errors=[];
$check=static function(bool $ok,string $why)use(&$errors):void{if(!$ok){$errors[]=$why;}};
DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','!=','delegation')->delete();
$facts=WatchFacts::for($watch->fresh());$check(!str_contains($facts['Checks']['Registry']['cause']??'','after the next check'),'Delegation alone cannot promise registry results');
$check(!str_contains($facts['Domain']['Expires']['cause']??'','after the next check'),'Delegation alone cannot promise expiry');
$check(str_contains($facts['Checks']['Nameservers']['cause']??'','after the next check'),'Delegation still promises nameserver results');
// An orphaned owner is excluded safely, not evidence that encrypted data is corrupt.
DB::table('domains_watch_names')->where('id',$id)->update(['user_id'=>$uid+100000]);$orphan=WatchName::find($id);$row=WatchFindings::summaries([$orphan])[$id];
$check($row['summary']===null,'Missing-owner fixture is excluded');$last=WatchFacts::lastCheck($orphan,$row['summary']);
$check(($last['cause']??null)!==WatchFacts::DECRYPT,'Missing owner is not a decryption diagnosis');
foreach(['paused'=>'Paused','retired'=>'Monitoring stopped; no further checks','active'=>'No check scheduled.']as$state=>$cause){
 DB::table('domains_watch_names')->where('id',$id)->update(['user_id'=>$uid,'state'=>$state]);DB::table('domains_watch_work')->where('watch_id',$id)->delete();
 $w=WatchName::find($id);$check(str_starts_with(WatchFacts::missingCause($w),$cause),$state.' list guidance respects saved state');}
expect($errors===[],implode('; ',$errors));$nativeSuccessful=true;echo "Watch state and per-work guidance passed\n";
