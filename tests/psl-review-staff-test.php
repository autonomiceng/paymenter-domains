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
Livewire::actingAs($staff)->test(Page::class)->assertActionHidden('psl_accept');same('updated',$list->update(),'First download');
$body=pslFixture(40,40);$clock->at+=86400;same('rules_lost_icann',$list->update(),'Rejected candidate');$hash=hash('sha256',$body);
$page=Livewire::actingAs($staff)->test(Page::class)->assertActionVisible('psl_accept')->assertActionHidden('psl_download')->mountAction('psl_accept')->assertActionMounted('psl_accept');
same('The new list removes 80 rules. Use it anyway?',$page->instance()->getMountedAction()->getModalDescription(),'Native modal states the removed count');
$schema=(new ReflectionMethod(Page::class,'getMountedActionSchema'))->invoke($page->instance());$text='';
foreach($schema->getComponents() as $component){if($component instanceof \Filament\Infolists\Components\TextEntry){$text.=implode("\n",(array)$component->getState())."\n";}}
foreach([Lists::SOURCE,'80 → 40','i40.example'] as $expected){expect(str_contains($text,$expected),'Native modal review facts: '.$expected);}
expect(!str_contains($text,'i60.example'),'Native modal limits samples to 20');
$accept=$page->instance()->getCachedHeaderActions()[1]->getActionFunction();
$staff->role()->first()->update(['permissions'=>[Staff::VIEW,Page::VIEW]]);
$denied=false;try{$accept([]);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$denied=$e->getStatusCode()===403;}expect($denied,'Cached acceptance rechecks permission');
$viewOnly=Livewire::actingAs($staff)->test(Page::class);
expect($viewOnly->instance()->getCachedHeaderActions()[1]->isHidden(),'View-only acceptance hidden without mounting protected schema');
$staff->role()->first()->update(['permissions'=>$rights]);
Livewire::actingAs($staff)->test(Page::class)->mountAction('psl_accept')
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('List updated');
same($hash,$list->revision(),'Native action accepted exact bytes');same(2,$calls,'Acceptance performs no I/O');same(1,DB::table('domains_watch_audit')->count(),'Native acceptance audited');
// Both entry points submit the bytes shown when the modal was mounted.
require_once __DIR__.'/Support/accounts.php';fixtureAccount(1,'namesilo','psl-review-account');
$accountPage=Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class;
$setupPage=Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::class;
$panel->pages([Page::class,$accountPage,$setupPage]);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel,$accountPage,$setupPage){$accountPage::registerRoutes($panel);$setupPage::registerRoutes($panel);});
app('router')->getRoutes()->refreshNameLookups();
foreach([false,true] as $embedded){
    $body=pslFixture(20,20);$clock->at+=86400;same('rules_lost_icann',$list->update(),'Old candidate ready for review');
    $action=$embedded?\Filament\Actions\Testing\TestAction::make('psl_accept')->schemaComponent('system_checks','content'):'psl_accept';
    $stale=Livewire::actingAs($staff)->test($embedded?$accountPage:Page::class,$embedded?['account'=>1]:[])->mountAction($action)->assertActionMounted($action);
    $beforeSnapshot=(array)$list->current();$auditCount=DB::table('domains_watch_audit')->count();
    $body=pslFixture(10,10);$clock->at+=86400;same('rules_lost_icann',$list->update(),'Candidate replaced after review');
    $stale->callMountedAction()->assertHasNoActionErrors()->assertNotified(\Filament\Notifications\Notification::make()->danger()->title('The download changed. Try again.'));
    same($beforeSnapshot,(array)$list->current(),'Stale reviewed bytes cannot change the snapshot');
    same($auditCount,DB::table('domains_watch_audit')->count(),'Stale reviewed bytes create no acceptance audit');
}
$hash=hash('sha256',$body);$priorCalls=$calls;
Livewire::actingAs($staff)->test($accountPage,['account'=>1])->mountAction($action)->callMountedAction()->assertNotified('List updated');
same($hash,$list->revision(),'Fresh account review accepts the displayed bytes');same($priorCalls,$calls,'Fresh acceptance has no network exchange');

$entry->upgraded('0.1.0');$entry->uninstalled();same(2,DB::table('domains_watch_audit')->count(),'Native lifecycle keeps audit');
DB::table('migrations')->where('migration','2026_10_19_000008_create_psl_reviews')->delete();
$refused=false;try{$entry->installed();}catch(DomainException $e){$refused=str_contains($e->getMessage(),'suffix review history');}expect($refused,'Missing review installation history refused');
echo "Installed suffix acceptance removed-rule samples, revoked permission, no I/O and retained migration history passed.\n";$nativeSuccessful=true;
