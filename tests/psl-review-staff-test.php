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
require_once __DIR__.'/Support/accounts.php';
$accountPage=Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class;
$setupPage=Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::class;
staffPanel();$panel=Filament::getCurrentPanel();$panel->pages([Page::class,$accountPage,$setupPage]);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel,$accountPage,$setupPage){Page::registerRoutes($panel);$accountPage::registerRoutes($panel);$setupPage::registerRoutes($panel);});
app('router')->getRoutes()->refreshNameLookups();
$action=\Filament\Actions\Testing\TestAction::make('psl_accept')->schemaComponent('system_checks','content');
$clock=new PslClock;$v=new PslValidator(128,5,1);$body=pslFixture(80,80);$calls=0;
$list=new Lists($clock,new PslTransport(function()use(&$calls,&$body){$calls++;return ['status'=>200,'body'=>$body];}),$v);
$reviews=new PslRebaseline($clock,$v);app()->instance(Lists::class,$list);app()->instance(PslRebaseline::class,$reviews);app()->instance(Clock::class,$clock);
// A monitoring-only site must recover discovery through the native page and inbox without adding a registrar server.
{
    $monitor=staffUser([Staff::VIEW,Page::VIEW,Page::MANAGE]);Auth::setUser($monitor);
    expect(!App\Models\Server::where('extension','Domains')->exists(),'Monitoring-only fixture has no registrar server');
    same('updated',$list->update(),'Standalone initial copy');$body=pslFixture(40,40);$clock->at+=(Lists::MAX_AGE_DAYS+1)*86400;
    $list->tick();expect(!$list->discoveryAllowed(),'Rejected replacement leaves expired discovery paused');
    expect(Page::shouldRegisterNavigation(),'Exceptional review appears in monitoring-only navigation');
    $standalone=Livewire::actingAs($monitor)->test(Page::class)->assertSuccessful()->assertNoRedirect()->assertActionVisible('psl_accept');
    same(Page::getUrl(),App\Models\Notification::where('user_id',$monitor->id)->latest('id')->value('url'),'Failure notice reaches the standalone review');
    $standalone->mountAction('psl_accept')->callMountedAction()->assertHasNoActionErrors()->assertNotified('List updated');
    expect($list->discoveryAllowed(),'Accepting the reviewed copy restores discovery without a registrar');same(2,$calls,'Standalone acceptance sends no extra download');
}
$auditBase=DB::table('domains_watch_audit')->count();$body=pslFixture(80,80);$clock->at+=86400;$calls=0;
fixtureAccount(1,'namesilo','psl-review-account');
$rights=[Staff::VIEW,Page::VIEW,Page::MANAGE];$staff=staffUser($rights);Auth::setUser($staff);
Livewire::actingAs($staff)->test(Page::class)->assertRedirect($setupPage::getUrl());same('updated',$list->update(),'First download');
$body=pslFixture(40,40);$clock->at+=86400;same('rules_lost_icann',$list->update(),'Rejected candidate');$hash=hash('sha256',$body);
$page=Livewire::actingAs($staff)->test($accountPage,['account'=>1])->assertActionVisible($action)->mountAction($action)->assertActionMounted($action);
same('The new list removes 80 rules. Use it anyway?',$page->instance()->getMountedAction()->getModalDescription(),'Native modal states the removed count');
$schema=(new ReflectionMethod($accountPage,'getMountedActionSchema'))->invoke($page->instance());$text='';
foreach($schema->getComponents() as $component){if($component instanceof \Filament\Infolists\Components\TextEntry){$text.=implode("\n",(array)$component->getState())."\n";}}
foreach([Lists::SOURCE,'80 → 40','i40.example'] as $expected){expect(str_contains($text,$expected),'Native modal review facts: '.$expected);}
expect(!str_contains($text,'i60.example'),'Native modal limits samples to 20');
$accept=Paymenter\Extensions\Servers\Domains\Admin\Support\PslActions::accept(fn()=>null)->getActionFunction();
$staff->role()->first()->update(['permissions'=>[Staff::VIEW,Page::VIEW]]);
$denied=false;try{$accept([]);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$denied=$e->getStatusCode()===403;}expect($denied,'Cached acceptance rechecks permission');
Livewire::actingAs($staff)->test($accountPage,['account'=>1])->assertActionDoesNotExist($action);
$staff->role()->first()->update(['permissions'=>$rights]);
Livewire::actingAs($staff)->test($accountPage,['account'=>1])->mountAction($action)
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('List updated');
same($hash,$list->revision(),'Native action accepted exact bytes');same(2,$calls,'Acceptance performs no I/O');same($auditBase+1,DB::table('domains_watch_audit')->count(),'Native acceptance audited');
// Submitting a stale modal cannot accept a replacement download.
{
    $body=pslFixture(20,20);$clock->at+=86400;same('rules_lost_icann',$list->update(),'Old candidate ready for review');
    $stale=Livewire::actingAs($staff)->test($accountPage,['account'=>1])->mountAction($action)->assertActionMounted($action);
    $beforeSnapshot=(array)$list->current();$auditCount=DB::table('domains_watch_audit')->count();
    $body=pslFixture(10,10);$clock->at+=86400;same('rules_lost_icann',$list->update(),'Candidate replaced after review');
    $stale->callMountedAction()->assertHasNoActionErrors()->assertNotified(\Filament\Notifications\Notification::make()->danger()->title('The download changed. Try again.'));
    same($beforeSnapshot,(array)$list->current(),'Stale reviewed bytes cannot change the snapshot');
    same($auditCount,DB::table('domains_watch_audit')->count(),'Stale reviewed bytes create no acceptance audit');
}
$hash=hash('sha256',$body);$priorCalls=$calls;
Livewire::actingAs($staff)->test($accountPage,['account'=>1])->mountAction($action)->callMountedAction()->assertNotified('List updated');
same($hash,$list->revision(),'Fresh account review accepts the displayed bytes');same($priorCalls,$calls,'Fresh acceptance has no network exchange');

$entry->upgraded('0.1.0');$entry->uninstalled();same($auditBase+2,DB::table('domains_watch_audit')->count(),'Native lifecycle keeps audit');
DB::table('migrations')->where('migration','2026_10_19_000008_create_psl_reviews')->delete();
$refused=false;try{$entry->installed();}catch(DomainException $e){$refused=str_contains($e->getMessage(),'suffix review history');}expect($refused,'Missing review installation history refused');
echo "Installed suffix acceptance removed-rule samples, revoked permission, no I/O and retained migration history passed.\n";$nativeSuccessful=true;
