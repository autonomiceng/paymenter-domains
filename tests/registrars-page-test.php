<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native registrar pages over synthetic account and checkout facts.
declare(strict_types=1);
require __DIR__.'/Support/registrars-page.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,DB,Route};
use Livewire\Livewire;
use Filament\Actions\Testing\TestAction;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList as PslPage;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{ActivationStatus,RegistrarAccount,RegistrarBalances as BalancesPage,RegistrarHistory,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, BalanceReads, BalanceSchema, ContactDetails, EnomTestSendLimit, EnomTestSendLimitSchema, ObservationSchema, RegistrarBalances, ReminderSchedules, ReminderSchema, RenewalBillingSettings, StaffSettingsReview};
// Navigation: one Registrars entry; Balances and Activation leave the menu and Balances redirects.
same('Registrars',RegistrarSetup::getNavigationLabel(),'Registrars navigation label');
foreach([BalancesPage::class,ActivationStatus::class,RegistrarAccount::class] as $page){expect(!$page::shouldRegisterNavigation(),$page.' is not in navigation');}
Livewire::actingAs($staff)->test(BalancesPage::class)->assertRedirect(RegistrarSetup::getUrl());
Livewire::actingAs($staff)->test(ActivationStatus::class)->assertRedirect(RegistrarSetup::getUrl());
Livewire::actingAs(staffUser([]))->test(RegistrarSetup::class)->assertForbidden();
// List page.
$list=Livewire::actingAs($staff)->test(RegistrarSetup::class)->assertSuccessful()->assertSee('Registrars')->assertActionDoesNotExist('add_account')->assertSee('Accounts');
staffTableStandard($list,'Search account',['name','note'],['balance','due']);
staffRowsStandard($list,['checked','domains'],['balance','due']);
// One line per account: the reason, a missing balance's cause and a low balance go in Note, each with its step.
$rows=$list->instance()->getTableRecords()->keyBy('id');$cell=fn(string $column,int $id)=>$list->instance()->getTable()->getColumn($column)->record($rows[$id]);
same('Low balance. Add funds.',$cell('note',$enom->server_id)->getState(),'Low balance says to add funds');
same('warn',$cell('note',$enom->server_id)->getExtraAttributes()['data-beco-tone']??null,'Low balance is a job');
same('Not checked yet. Use Check balance.',$cell('note',$silo->server_id)->getState(),'A Missing balance has its step in Note');
same('Off in Paymenter.',$cell('note',$off->id)->getState(),'The status reason is in Note');
same([null,null],[$cell('note',$empty->id)->getState(),$cell('note',$empty->id)->getExtraAttributes()['data-beco-tone']??null],'Nothing to say, no Note');
same(['Enom','1','NameSilo','0'],[$cell('registrar',$enom->server_id)->getState(),(string)$cell('domains',$enom->server_id)->getState(),$cell('registrar',$silo->server_id)->getState(),(string)$cell('domains',$silo->server_id)->getState()],'Registrar and domain count have columns');
expect(str_contains((string)$cell('checked',$enom->server_id)->formatState($cell('checked',$enom->server_id)->getState()),'data-domains-time'),'Balance checked shows in the viewer time');
// Each read-only filter case hydrates its own copy of the same initial native page.
// This keeps filters independent and tests every real request without remounting an unchanged list.
// No accounts yet says how to add one; a search that hides every account says so.
(clone $list)->searchTable('no-such-account')->assertSee('No accounts match')->assertSee('Clear the search or filters to see all rows.')->assertDontSee('Add one to start selling domains.');
foreach(['Account','Registrar','Mode','Status','Note','Balance','Due in 30 days','Enom test account','Test','Active','USD 7.99','Low balance','USD 8.00',
    'Missing','Not checked yet','Add funds','Not connected','Connect','Off in Paymenter','Demo NameSilo','Demo'] as $needle){$list->assertSee($needle);}
$html=$list->html();
foreach(['https://ote.namesilo.com/cart/add-funds','https://cp.enom.com/','noopener noreferrer',RegistrarAccount::getUrl(['account'=>$enom->server_id]),RegistrarAccount::getUrl(['account'=>$silo->server_id])] as $needle){
    expect(str_contains($html,$needle),'List links: '.$needle);}
foreach(['Registrar setup','Registrar balances','Server #','Unknown','Viewing uses saved local facts','unavailable','Unavailable',"\u{2014}",'account-enom','account-namesilo',$enom->account_fingerprint] as $needle){
    expect(!str_contains($html,$needle),'List never shows: '.$needle);}
$missing=ContactDetails::missingRequiredProperties();
if($missing){$list->assertSee("Customers can't enter")->assertSee('Add them as custom properties.')->assertSee('Open custom properties');}
// Search and filters work on this short list too.
(clone $list)->assertSee('Search account')->searchTable('Spare')->assertSee('Spare account')->assertDontSee('Old account');
(clone $list)->filterTable('registrar','namesilo')->assertSee('Demo NameSilo')->assertDontSee('Enom test account')->assertDontSee('Spare account');
(clone $list)->filterTable('mode','live')->assertDontSee('Enom test account');
(clone $list)->filterTable('status','Not connected')->assertSee('Spare account')->assertDontSee('Enom test account');
// Native create permission and a usable route are both required.
$creator=staffUser([Staff::VIEW,'admin.servers.create']);
$routes=app('router')->getRoutes();$withoutCreate=new Illuminate\Routing\RouteCollection;
$createName=\App\Admin\Resources\ServerResource::getRouteBaseName($panel).'.create';
foreach($routes as $route){if($route->getName()!==$createName){$withoutCreate->add($route);}}
app('router')->setRoutes($withoutCreate);
Livewire::actingAs($creator)->test(RegistrarSetup::class)->assertActionDoesNotExist('add_account');
app('router')->setRoutes($routes);
Livewire::actingAs($creator)->test(RegistrarSetup::class)->assertActionVisible('add_account')
    ->assertActionHasUrl('add_account',\App\Admin\Resources\ServerResource::getUrl('create'));
$creator->role()->first()->update(['permissions'=>[Staff::VIEW]]);$creator->unsetRelation('role');
Livewire::actingAs($creator)->test(RegistrarSetup::class)->assertActionDoesNotExist('add_account');
echo "Registrar list, permissions, filters and credit setup passed.\n";$nativeSuccessful=true;
