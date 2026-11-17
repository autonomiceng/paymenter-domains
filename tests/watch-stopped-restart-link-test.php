<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';require __DIR__.'/Support/storefront.php';
use Illuminate\Support\Facades\{Auth,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ViewWatch;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
$nativeSuccessful=false;
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';$f=noticeFixture();extract($f);\Paymenter\Extensions\Servers\Domains\Services\StaffWatchSchema::install();staffPanel();
$panel=Filament::getCurrentPanel();$resources=[WatchResource::class,\Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource::class,\Paymenter\Extensions\Servers\Domains\Admin\Resources\PendingAdoptionResource::class];$panel->resources($resources);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resources,$panel){foreach($resources as $r){$r::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
// The hosting service ended, so monitoring stopped (Nov 16 walk: Monitored domains all Stopped).
DB::transaction(function()use($id){$w=WatchName::whereKey($id)->lockForUpdate()->firstOrFail();$w->sources()->where('state','watch')->orderBy('id')->lockForUpdate()->get()->each(fn($s)=>$s->retire('Hosting service ended'));$w->state='retired';$w->revision++;$w->save();});
$settings=WatchResource::getUrl('settings');
$links=static function($user)use($id,$settings):array{
    Auth::setUser($user);$dom=Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8">'.Livewire::actingAs($user)->test(ViewWatch::class,['record'=>$id])->assertSuccessful()->html(),LIBXML_NOERROR);
    return array_values(array_filter(iterator_to_array($dom->querySelectorAll('a[href]')),fn($a)=>$a->getAttribute('href')===$settings));
};
$manager=staffUser([Staff::VIEW,Writes::VIEW,Writes::MANAGE]);
$found=$links($manager);
expect(count($found)===1&&trim($found[0]->textContent)!=='','A stopped domain links staff to the monitoring setting that starts it again');
echo "A stopped monitored domain shows staff the way to start monitoring again.\n";
$nativeSuccessful=true;
