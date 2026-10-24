<?php
// Registrars list and account page: status, balance, Due in 30 days and Add funds; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/staff-panel.php';
require_once __DIR__.'/Support/accounts.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,DB,Route};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{ActivationStatus,RegistrarAccount,RegistrarBalances as BalancesPage,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,BalanceReads,BalanceSchema,CatalogueAcceptances,ContactDetails,EnomTestCallSchema,EnomTestCalls,ObservationSchema,RegistrarBalances};
extract(commerceFixture(true));$clock=new Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now);
app()->instance(AccountBindings::class,$bindings);app()->instance(Clock::class,$clock);app()->instance(RegistrarBalances::class,new RegistrarBalances($bindings,$clock,new Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock)));BalanceSchema::install();ObservationSchema::install();EnomTestCallSchema::install();
$enom=Account::where('server_id',$tlds[0]->server_id)->sole();$silo=Account::where('server_id',$tlds[1]->server_id)->sole();
App\Models\Server::whereKey($enom->server_id)->update(['name'=>'Enom test account']);App\Models\Server::whereKey($silo->server_id)->update(['name'=>'NameSilo test account']);
$user=commerceUser();$product=$native->product($tlds[0],'register');
$order=App\Models\Order::create(['user_id'=>$user->id,'currency_code'=>'USD']);
$service=App\Models\Service::create(['user_id'=>$user->id,'order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$native->annualPlan($product)->id,
    'quantity'=>1,'price'=>'10.00','currency_code'=>'USD','status'=>'active']);
// One renewal due within 30 days at registrar cost 8.00; the balance is one cent short.
Domain::create(['domain'=>placeholderComFixtureName('registrars-due-placeholder.com'),'sld'=>'registrars-due-placeholder','tld'=>'com','user_id'=>$user->id,'service_id'=>$service->id,
    'server_id'=>$enom->server_id,'registrar_key'=>'enom','account_fingerprint'=>$enom->account_fingerprint,'status'=>'active','provider_expires_at'=>'2026-11-10','renewal_policy'=>[]]);
BalanceReads::record(CatalogueAcceptances::binding($enom),new Money('7.99','USD'),$clock->now(),'manual');
$facts=RegistrarBalances::facts($enom,$clock->now());
same('USD 7.99',$facts['balance'],'Staff money puts the currency first');same('Low balance',$facts['status'],'Balance below Due in 30 days is Low balance');
same('USD 8.00',$facts['total'],'Due in 30 days');same('Oct 18, 2026 12:00 UTC',$facts['checked'],'Checked date in staff format');same(null,$facts['cause'],'A read balance has no cause');
$siloFacts=RegistrarBalances::facts($silo,$clock->now());same(null,$siloFacts['balance'],'Unread balance is Missing');same('Not checked yet',$siloFacts['cause'],'Missing balance names its cause');
same('https://ote.namesilo.com/cart/add-funds',RegistrarBalances::fundsUrl('namesilo','test'),'NameSilo Test funds the sandbox account');
same('https://www.namesilo.com/cart/add-funds',RegistrarBalances::fundsUrl('namesilo','live'),'NameSilo Live funds the live account');
same('https://cp.enom.com/',RegistrarBalances::fundsUrl('enom','test'),'Enom funding home');
// A server without an account, a disabled server and a seeded demo account.
$empty=App\Models\Server::create(['name'=>'Spare account','extension'=>'Domains','type'=>'server','enabled'=>true]);
$off=App\Models\Server::create(['name'=>'Old account','extension'=>'Domains','type'=>'server','enabled'=>false]);
$demoId=(int)App\Models\Server::max('id')+1;fixtureAccount($demoId,'namesilo','beco-demo-namesilo');$demo=Account::where('server_id',$demoId)->sole();
App\Models\Server::whereKey($demo->server_id)->update(['name'=>'Demo NameSilo']);
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
staffPanel();$panel=Filament::getCurrentPanel();$pages=[RegistrarSetup::class,RegistrarAccount::class,BalancesPage::class,ActivationStatus::class];$panel->pages($pages);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel,$pages){foreach($pages as $page){$page::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$staff=staffUser([Staff::VIEW]);Auth::setUser($staff);
// Navigation: one Registrars entry; Balances and Activation leave the menu and Balances redirects.
same('Registrars',RegistrarSetup::getNavigationLabel(),'Registrars navigation label');
foreach([BalancesPage::class,ActivationStatus::class,RegistrarAccount::class] as $page){expect(!$page::shouldRegisterNavigation(),$page.' is not in navigation');}
Livewire::actingAs($staff)->test(BalancesPage::class)->assertRedirect(RegistrarSetup::getUrl());
Livewire::actingAs(staffUser([]))->test(RegistrarSetup::class)->assertForbidden();
// List page.
$list=Livewire::actingAs($staff)->test(RegistrarSetup::class)->assertSuccessful()->assertSee('Registrars')->assertSee('Add registrar account')->assertSee('Accounts');
foreach(['Account','Mode','Status','Balance','Due in 30 days','Enom test account','Enom · 1 domain','NameSilo · 0 domains','Test','Active','USD 7.99','Low balance','USD 8.00',
    'Missing','Not checked yet','Add funds','Not connected','Connect','Off in Paymenter','Demo NameSilo','Demo'] as $needle){$list->assertSee($needle);}
$html=$list->html();
foreach(['https://ote.namesilo.com/cart/add-funds','https://cp.enom.com/','noopener noreferrer',RegistrarAccount::getUrl(['account'=>$enom->server_id]),RegistrarAccount::getUrl(['account'=>$silo->server_id])] as $needle){
    expect(str_contains($html,$needle),'List links: '.$needle);}
foreach(['Registrar setup','Registrar balances','Server #','Unknown','Viewing uses saved local facts','unavailable','Unavailable',"\u{2014}",'account-enom','account-namesilo',$enom->account_fingerprint] as $needle){
    expect(!str_contains($html,$needle),'List never shows: '.$needle);}
$missing=ContactDetails::missingRequiredProperties();
if($missing){$list->assertSee("Customers can't enter")->assertSee('Add them as custom properties.')->assertSee('Open custom properties');}
// Account page.
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSuccessful();
foreach(['Enom test account','Active','Check balance','Account','Registrar','Enom','Mode','Test','Balance','USD 7.99','Low balance','Add funds','Due in 30 days','USD 8.00',
    'Domains','API calls today','0 of 50','Last sync','Not synced yet'] as $needle){$page->assertSee($needle);}
$html=$page->html();
foreach(['https://cp.enom.com/','Registrars'] as $needle){expect(str_contains($html,$needle),'Account page: '.$needle);}
foreach(['Server #','Unknown','Enom TEST daily calls','Verified user ID','Retained domains','Environment',"\u{2014}"] as $needle){expect(!str_contains($html,$needle),'Account page never shows: '.$needle);}
// System checks: a failing check shows in danger with its cause; passing checks stay plain.
Illuminate\Support\Facades\Event::forget(App\Events\Invoice\Paid::class);
$flat=$page->instance()->content(\Filament\Schemas\Schema::make($page->instance()))->getFlatComponents();
$check=static fn(string $label)=>array_values(array_filter($flat,fn($e)=>$e instanceof \Filament\Infolists\Components\TextEntry&&$e->getLabel()===$label))[0];
expect(str_starts_with($check('Start after payment')->getState(),'Paused · the invoice-paid listener is missing.'),'Failing check keeps its cause');
same('danger',$check('Start after payment')->getColor($check('Start after payment')->getState()),'Failing check is danger');
same(null,$check('Native server settings')->getColor($check('Native server settings')->getState()),'Passing check is plain');
DB::table(EnomTestCallSchema::TABLE)->insert(['day'=>EnomTestCalls::day(),'used'=>50]);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('50 of 50')->assertSee('Resets 00:00 UTC');
config(['domains.enom_test_daily_call_cap'=>'many']);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('Missing')->assertSee('Enom test call limit is not set in server config.');
config(['domains.enom_test_daily_call_cap'=>50]);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$silo->server_id])->assertSee('NameSilo test account')->assertSee('Missing')->assertSee('Not checked yet')
    ->assertSee('Add funds')->assertSeeHtml('https://ote.namesilo.com/cart/add-funds')->assertDontSee('API calls today');
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$off->id])->assertSee('Not connected')->assertSee('Off in Paymenter')->assertDontSee('Add funds');
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$empty->id])->assertSee('Not connected')->assertDontSee('Check balance');
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$demo->server_id])->assertSee('Demo NameSilo')->assertSee('Demo');
// Orders paused: by staff, and by an unconfirmed paid request.
DB::table('domains_accounts')->where('server_id',$silo->server_id)->update(['processing_enabled'=>false]);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$silo->server_id])->assertSee('Orders paused')->assertSee('Paused by staff');
DB::table('domains_accounts')->where('server_id',$silo->server_id)->update(['processing_enabled'=>true]);
$siloService=$service->replicate();$siloService->save();
$siloDomain=Domain::create(['domain'=>placeholderComFixtureName('registrars-held-placeholder.com'),'sld'=>'registrars-held-placeholder','tld'=>'com','user_id'=>$user->id,'service_id'=>$siloService->id,
    'server_id'=>$silo->server_id,'registrar_key'=>'namesilo','account_fingerprint'=>$silo->account_fingerprint,'status'=>'registration_pending','renewal_policy'=>[]]);
$held=Paymenter\Extensions\Servers\Domains\Models\Operation::create(['domain_id'=>$siloDomain->id,'server_id'=>$silo->server_id,'registrar_key'=>'namesilo','account_fingerprint'=>$silo->account_fingerprint,
    'service_id'=>$siloService->id,'authority_generation'=>$siloDomain->authority_generation,'operation_key'=>'registrars-uncertain','action'=>'register','term'=>1,'currency'=>'USD','amount'=>'10.00',
    'funding_basis'=>'paid_invoice','state'=>'uncertain','payload'=>[],'policy'=>[],'attempt'=>[],'funding'=>[]]);
Livewire::actingAs($staff)->test(RegistrarSetup::class)->assertSee('Orders paused')->assertSee('Request #'.$held->id.' unconfirmed');
// Check balance: a failed read and the one-minute wait use the new toasts.
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->callAction('check_balance')->assertNotified("Couldn't check balance");
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->callAction('check_balance')->assertNotified('Wait a minute before checking again.');
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>999999])->assertNotFound();
Livewire::actingAs(staffUser([]))->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertForbidden();
echo "Registrars list and account page passed\n";$nativeSuccessful=true;
