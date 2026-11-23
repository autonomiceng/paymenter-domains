<?php
// DNS template staff pages over synthetic registration settings.
declare(strict_types=1);
require __DIR__.'/watch-dns.php';require __DIR__.'/domain-name-cell.php';require __DIR__.'/storefront.php';
use Illuminate\Support\Facades\{Auth,Crypt,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
require dirname(__DIR__,2).'/Domains/routes/web.php';require dirname(__DIR__,2).'/Domains/routes/owned.php';
extract(dnsFixture()); DB::transaction(fn()=> $profile->update(['enabled'=>true,'revision'=>2,'reason'=>'Synthetic template enabled']));
staffPanel();$panel=Filament::getCurrentPanel();$resources=[RoutingProfileResource::class];$panel->resources($resources);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resources,$panel){foreach($resources as$r){$r::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$manager=staffUser([Staff::VIEW,Writes::VIEW,Writes::MANAGE]);Auth::setUser($manager);
$clean=function(string $html,string $page):void{$text=html_entity_decode(strip_tags($html));expect(!str_contains($text,'—'),'No em dash on '.$page);foreach(['unavailable','Saved revision','revision','feature gates','Domain watch','routing profile','mapping'] as $word){expect(stripos($text,$word)===false,'No "'.$word.'" on '.$page);}};
$column=fn($page,string $name)=>$page->instance()->getTable()->getColumn($name);
