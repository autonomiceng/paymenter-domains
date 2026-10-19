<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php'; require __DIR__.'/Support/staff-panel.php'; require __DIR__.'/Support/psl.php';
use Illuminate\Support\Facades\{Auth,DB,Route};
use Filament\Facades\Filament;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList as Page;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{PublicSuffixList as Lists,PslTransport,PslValidator,PslRebaseline};
$entry=new Paymenter\Extensions\Servers\Domains\Domains([]);$entry->installed();$entry->boot();
staffPanel();$panel=Filament::getCurrentPanel();$panel->pages([Page::class]);
Route::name('filament.admin.')->prefix($panel->getPath())->middleware($panel->getMiddleware())->group(fn()=>Route::middleware($panel->getAuthMiddleware())->group(fn()=>Page::registerRoutes($panel)));
app('router')->getRoutes()->refreshNameLookups();app('router')->getRoutes()->refreshActionLookups();
$clock=new PslClock;$v=new PslValidator(128,5,1);$body=pslFixture(80,80);$calls=0;
$list=new Lists($clock,new PslTransport(function()use(&$calls,&$body){$calls++;return ['status'=>200,'body'=>$body];}),$v);
$reviews=new PslRebaseline($clock,$v);app()->instance(Lists::class,$list);app()->instance(PslRebaseline::class,$reviews);app()->instance(Clock::class,$clock);
$rights=[Staff::VIEW,Page::VIEW,Page::MANAGE];$staff=staffUser($rights);Auth::setUser($staff);
Livewire::actingAs($staff)->test(Page::class)->assertActionHidden('accept');same('updated',$list->update(),'First download');
$body=pslFixture(40,40);$clock->at+=86400;same('rules_lost_icann',$list->update(),'Rejected candidate');$hash=hash('sha256',$body);
$page=Livewire::actingAs($staff)->test(Page::class)->assertActionVisible('accept')->mountAction('accept')->assertActionMounted('accept');
$page->assertActionDataSet(['revision'=>$hash]);
$page->setActionData(['revision'=>$hash,'confirmation'=>'wrong','reason'=>'Reviewed'])->callMountedAction()->assertHasActionErrors(['confirmation']);
$schema=(new ReflectionMethod(Page::class,'getMountedActionSchema'))->invoke($page->instance());$text='';
foreach($schema->getComponents() as $component){if($component instanceof \Filament\Infolists\Components\TextEntry){$text.=implode("\n",$component->getState());}}
foreach([$hash,Lists::SOURCE,'ICANN: 40 removed.','PRIVATE: 40 removed.','i40.example'] as $expected){expect(str_contains($text,$expected),'Native modal review facts: '.$expected);}
expect(!str_contains($text,'i60.example'),'Native modal limits samples to 20');
$accept=$page->instance()->getCachedHeaderActions()[2]->getActionFunction();
$staff->role()->first()->update(['permissions'=>[Staff::VIEW,Page::VIEW]]);
$denied=false;try{$accept(['revision'=>$hash,'confirmation'=>substr($hash,0,12),'reason'=>'Reviewed']);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$denied=$e->getStatusCode()===403;}expect($denied,'Cached acceptance rechecks permission');
$viewOnly=Livewire::actingAs($staff)->test(Page::class);
expect($viewOnly->instance()->getCachedHeaderActions()[2]->isHidden(),'View-only acceptance hidden without mounting protected schema');
$staff->role()->first()->update(['permissions'=>$rights]);
Livewire::actingAs($staff)->test(Page::class)->mountAction('accept')->setActionData(['revision'=>$hash,'confirmation'=>substr($hash,0,12),'reason'=>'Reviewed removal'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Public suffix list accepted');
same($hash,$list->revision(),'Native action accepted exact bytes');same(2,$calls,'Acceptance performs no I/O');same(1,DB::table('domains_watch_audit')->count(),'Native acceptance audited');
$entry->upgraded('0.1.0');$entry->uninstalled();same(1,DB::table('domains_watch_audit')->count(),'Native lifecycle keeps audit');
DB::table('migrations')->where('migration','2026_10_19_000008_create_psl_reviews')->delete();
$refused=false;try{$entry->installed();}catch(DomainException $e){$refused=str_contains($e->getMessage(),'suffix review history');}expect($refused,'Missing review installation history refused');
echo "Installed suffix acceptance removed-rule samples, typed confirmation, revoked permission, no I/O and retained migration history passed.\n";$nativeSuccessful=true;
