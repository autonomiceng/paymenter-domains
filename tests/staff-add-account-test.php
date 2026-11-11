<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Add registrar account names the account in a dialog and opens its page to connect, never the stock Create Server form.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
(new Paymenter\Extensions\Servers\Domains\Domains([]))->installed();
staffPanel();
$panel=\Filament\Facades\Filament::getPanel('admin');
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->middleware($panel->getMiddleware())
    ->group(function()use($panel){RegistrarSetup::registerRoutes($panel);RegistrarAccount::registerRoutes($panel);});
app('router')->getRoutes()->refreshNameLookups(); app('router')->getRoutes()->refreshActionLookups();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
$creator=staffUser([Staff::VIEW,'admin.servers.create']);
$page=Livewire::actingAs($creator)->test(RegistrarSetup::class)->assertActionVisible('add_account');
same(null,$page->instance()->getAction('add_account')->getUrl(),'The button opens a dialog, not the stock Create Server page');
$before=App\Models\Server::count();
foreach(['','   ',str_repeat('n',256)] as $name){
    Livewire::actingAs($creator)->test(RegistrarSetup::class)->callAction('add_account',['name'=>$name])->assertHasActionErrors(['name']);
    same($before,App\Models\Server::count(),'An invalid account name creates no server');
}
$page=Livewire::actingAs($creator)->test(RegistrarSetup::class)->callAction('add_account',['name'=>'  Enom live account '])->assertHasNoActionErrors()->assertNotified(\Filament\Notifications\Notification::make()->success()->title('Registrar account added')->body('Use Connect account to finish.'));
$new=App\Models\Server::where('name','Enom live account')->sole();
same(['Domains','server',true],[$new->extension,$new->type,(bool)$new->enabled],'The new account is a Domains server');
$page->assertRedirect(RegistrarAccount::getUrl(['account'=>$new->id]));
// A failed enabled hook leaves the account available for repair, with no success notice.
$receipt=Illuminate\Support\Facades\DB::table('migrations')->where('migration','2026_10_19_000014_add_holding_adoption')->sole();
App\Models\Server::created(static function($server)use($receipt):void{if($server->name==='Account needing setup'){Illuminate\Support\Facades\DB::table('migrations')->where('id',$receipt->id)->delete();}});
$before=App\Models\Server::count();$levels=[];
Illuminate\Support\Facades\DB::listen(static function($query)use(&$levels):void{if(str_contains($query->sql,'migrations')){$levels[]=Illuminate\Support\Facades\DB::transactionLevel();}});
$page=Livewire::actingAs($creator)->test(RegistrarSetup::class)->callAction('add_account',['name'=>'Account needing setup'])
    ->assertNotified(\Filament\Notifications\Notification::make()->warning()->title('Registrar account added; setup failed')->body('The account was saved. Ask your site admin to finish Domains setup, then use Connect account.'))
    ->assertNotNotified('Registrar account added');
same($before+1,App\Models\Server::count(),'Setup failure preserves the new Server');
$failed=App\Models\Server::where('name','Account needing setup')->sole();
$page->assertRedirect(RegistrarAccount::getUrl(['account'=>$failed->id]));
expect($levels!==[] && array_unique($levels)===[0],'Installation checks run outside transactions');
Illuminate\Support\Facades\DB::table('migrations')->insert((array)$receipt);
// Re-check permission when a dialog mounted with permission is submitted after revocation.
$mounted=Livewire::actingAs($creator)->test(RegistrarSetup::class)->mountAction('add_account');
$creator->role->update(['permissions'=>[Staff::VIEW]]);$before=App\Models\Server::count();
$mounted->setActionData(['name'=>'Revoked account'])->callMountedAction();
same($before,App\Models\Server::count(),'A revoked mounted action creates no server');
Livewire::actingAs(staffUser([Staff::VIEW]))->test(RegistrarSetup::class)->assertActionDoesNotExist('add_account');
$nativeSuccessful=true;echo "Staff add account passed\n";
