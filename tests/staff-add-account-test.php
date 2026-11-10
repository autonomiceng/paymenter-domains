<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Add registrar account names the account in a dialog and opens its page to connect, never the stock Create Server form.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
staffPanel(); staffData(); Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::install();
$panel=\Filament\Facades\Filament::getPanel('admin');
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->middleware($panel->getMiddleware())
    ->group(function()use($panel){RegistrarSetup::registerRoutes($panel);RegistrarAccount::registerRoutes($panel);});
app('router')->getRoutes()->refreshNameLookups(); app('router')->getRoutes()->refreshActionLookups();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
$creator=staffUser([Staff::VIEW,'admin.servers.create']);
$page=Livewire::actingAs($creator)->test(RegistrarSetup::class)->assertActionVisible('add_account');
same(null,$page->instance()->getAction('add_account')->getUrl(),'The button opens a dialog, not the stock Create Server page');
$page->callAction('add_account',['name'=>''])->assertHasActionErrors(['name'=>'required']);
$page=Livewire::actingAs($creator)->test(RegistrarSetup::class)->callAction('add_account',['name'=>'  Enom live account '])->assertHasNoActionErrors();
$new=App\Models\Server::where('name','Enom live account')->sole();
same(['Domains','server',true],[$new->extension,$new->type,(bool)$new->enabled],'The new account is a Domains server');
$page->assertRedirect(RegistrarAccount::getUrl(['account'=>$new->id]));
Livewire::actingAs(staffUser([Staff::VIEW]))->test(RegistrarSetup::class)->assertActionDoesNotExist('add_account');
$nativeSuccessful=true;echo "Staff add account passed\n";
