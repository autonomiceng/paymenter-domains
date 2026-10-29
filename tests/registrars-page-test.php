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
use Filament\Actions\Testing\TestAction;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList as PslPage;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{ActivationStatus,RegistrarAccount,RegistrarBalances as BalancesPage,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,BalanceReads,BalanceSchema,CatalogueAcceptances,ContactDetails,EnomTestSendLimit,EnomTestSendLimitSchema,ObservationSchema,RegistrarBalances,ReminderSchedules,ReminderSchema,StaffSettingsReview};
// Expose the unchanged factories even when the real checklist omits their buttons.
class RegistrarAccountLiveActionsTestPage extends RegistrarAccount
{
    protected function getHeaderActions(): array
    {
        return array_map(fn(string $name)=>Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions::setup($this->account,$this->reviewStamp,$name),['live_renewal','live_management']);
    }
}
extract(commerceFixture(true));$clock=new Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now);
app()->instance(AccountBindings::class,$bindings);app()->instance(Clock::class,$clock);app()->instance(RegistrarBalances::class,new RegistrarBalances($bindings,$clock,new Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock)));BalanceSchema::install();ObservationSchema::install();EnomTestSendLimitSchema::install();
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
$demoId=(int)App\Models\Server::max('id')+1;fixtureAccount($demoId,'namesilo','demo-namesilo');$demo=Account::where('server_id',$demoId)->sole();
App\Models\Server::whereKey($demo->server_id)->update(['name'=>'Demo NameSilo']);
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
staffPanel();$panel=Filament::getCurrentPanel();$pages=[RegistrarSetup::class,RegistrarAccount::class,BalancesPage::class,ActivationStatus::class,PslPage::class];$panel->pages($pages);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel,$pages){foreach($pages as $page){$page::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$staff=staffUser([Staff::VIEW]);Auth::setUser($staff);
// Navigation: one Registrars entry; Balances and Activation leave the menu and Balances redirects.
same('Registrars',RegistrarSetup::getNavigationLabel(),'Registrars navigation label');
foreach([BalancesPage::class,ActivationStatus::class,RegistrarAccount::class] as $page){expect(!$page::shouldRegisterNavigation(),$page.' is not in navigation');}
Livewire::actingAs($staff)->test(BalancesPage::class)->assertRedirect(RegistrarSetup::getUrl());
Livewire::actingAs($staff)->test(ActivationStatus::class)->assertRedirect(RegistrarSetup::getUrl());
Livewire::actingAs(staffUser([]))->test(RegistrarSetup::class)->assertForbidden();
// List page.
$list=Livewire::actingAs($staff)->test(RegistrarSetup::class)->assertSuccessful()->assertSee('Registrars')->assertActionDoesNotExist('add_account')->assertSee('Accounts');
foreach(['Account','Mode','Status','Balance','Due in 30 days','Enom test account','Enom · 1 domain','NameSilo · 0 domains','Test','Active','USD 7.99','Low balance','USD 8.00',
    'Missing','Not checked yet','Add funds','Not connected','Connect','Off in Paymenter','Demo NameSilo','Demo'] as $needle){$list->assertSee($needle);}
$html=$list->html();
foreach(['https://ote.namesilo.com/cart/add-funds','https://cp.enom.com/','noopener noreferrer',RegistrarAccount::getUrl(['account'=>$enom->server_id]),RegistrarAccount::getUrl(['account'=>$silo->server_id])] as $needle){
    expect(str_contains($html,$needle),'List links: '.$needle);}
foreach(['Registrar setup','Registrar balances','Server #','Unknown','Viewing uses saved local facts','unavailable','Unavailable',"\u{2014}",'account-enom','account-namesilo',$enom->account_fingerprint] as $needle){
    expect(!str_contains($html,$needle),'List never shows: '.$needle);}
$missing=ContactDetails::missingRequiredProperties();
if($missing){$list->assertSee("Customers can't enter")->assertSee('Add them as custom properties.')->assertSee('Open custom properties');}
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
// Account page.
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSuccessful();
foreach(['Enom test account','Active','Check balance','Account','Registrar','Enom','Mode','Test','Balance','USD 7.99','Low balance','Add funds','Due in 30 days','USD 8.00',
    'Domains','API calls, last 24 hours','0 of 1000','Last sync','Not synced yet'] as $needle){$page->assertSee($needle);}
$html=$page->html();
foreach(['https://cp.enom.com/','Registrars'] as $needle){expect(str_contains($html,$needle),'Account page: '.$needle);}
foreach(['Server #','Unknown','Enom TEST daily calls','Verified user ID','Retained domains','Environment',"\u{2014}"] as $needle){expect(!str_contains($html,$needle),'Account page never shows: '.$needle);}
// Setup checklist, renewal section and header actions for staff who can change settings.
ReminderSchema::install();ReminderSchedules::enroll((int)$enom->server_id);
$admin=staffUser([Staff::VIEW,Staff::POLICY,...array_map([StaffSettingsReview::class,'permission'],array_keys(StaffSettingsReview::ACTIONS))]);
// Setup buttons show only when they can run: bind a writer that can reach the registrar.
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites::class,new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites(app(\Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::class),app(\Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class),app(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class),true));
$page=Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSuccessful();
$reminderAction=TestAction::make('reminders_'.$enom->server_id)->schemaComponent('account_'.$enom->server_id,'content');
$beforeInvalidReason=(array)DB::table('domains_reminder_schedules')->where('server_id',$enom->server_id)->sole();
$page->callAction($reminderAction,['reason'=>"Reviewed\u{2029}reminders"])->assertHasActionErrors(['reason'=>'regex']);
same($beforeInvalidReason,(array)DB::table('domains_reminder_schedules')->where('server_id',$enom->server_id)->sole(),'Reminder reason field error changes no schedule');
$page->unmountAction();
foreach(['Setup checklist','API connection','Connected','Test connection','Supported TLDs','.com','TLD prices','Sync TLD prices','Registrations','Transfers','Renewals','DNS, nameservers and contacts',
    'Renewal invoices','Turn on','Renewal invoices and reminders','Invoice issued','21 days before expiry','Invoice due','7 days before expiry','Reminder emails',
    '60, 30, 7 and 1 days before, 3 days after','Customer can renew early','Edit invoices','Edit reminders','Pause orders','More','Edit API credentials','View price history',
    'System checks','Background worker','Not running','Payment events'] as $needle){$page->assertSee($needle);}
$html=$page->html();
foreach(['Catalogue acceptance','Account binding','Worker cadence','Native server settings','Registrar setup','(defaults)','Read capacity','Runtime account','Notification safety',
    'Verify identity','Read balance','Enable processing','Pause processing','Missing Paymenter records','Transfer watch relinks','Start after payment','Change recorded','Confirm'] as $needle){
    expect(!str_contains($html,$needle),'Account page never shows: '.$needle);}
// Enom-only controls explain their scope for both NameSilo modes.
foreach(['test','live'] as $mode){
    $siloAccount=Account::where('server_id',$silo->server_id)->sole();DB::table('domains_accounts')->where('id',$siloAccount->id)->update(['environment'=>$mode]);$siloAccount->refresh();
    $featureRows=array_column(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup($siloAccount,['label'=>'Active','reason'=>null]),null,'label');
    foreach(['Renewals','DNS, nameservers and contacts'] as $label){
        same(['Off','Enom accounts only.',null],[$featureRows[$label]['value'],$featureRows[$label]['cause'],$featureRows[$label]['action']],$mode.' NameSilo Enom-only control');
    }
    Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$silo->server_id])->assertSee('Enom accounts only.')
        ->assertActionDoesNotExist(setupAction($mode.'_renewal',(int)$silo->server_id))->assertActionDoesNotExist(setupAction($mode.'_management',(int)$silo->server_id));
}
DB::table('domains_accounts')->where('id',$siloAccount->id)->update(['environment'=>'test']);

$reminderQuery=DB::table('domains_reminder_schedules')->where('server_id',$enom->server_id);$savedReminder=(array)(clone $reminderQuery)->sole();
foreach([[[1],'1 day before, 1 day after'],[[0],'0 days before, 0 days after'],[[2],'2 days before, 2 days after'],[[1,2],'2 and 1 days before, 1 and 2 days after'],[[],null]] as [$days,$expected]){
    $reminderQuery->update(['before_days'=>json_encode($days),'after_days'=>json_encode($days)]);
    same($expected,Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderFacts::emails((int)$enom->server_id)['cause'],'Reminder day grammar: '.json_encode($days));
    if($expected!==null){Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee($expected);}
}
$reminderQuery->update(['before_days'=>'{']);
same('Saved reminder days cannot be read. Ask an administrator to restore the saved schedule.',Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderFacts::emails((int)$enom->server_id)['cause'],'Unreadable reminders retain their cause');
$reminderQuery->update($savedReminder);Auth::setUser($admin);

// A cause shows under a value only when there is a problem: a synced price or a valid invoice day has none.
$enomAccount=Account::where('server_id',$enom->server_id)->sole();
$rows=array_column([...Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup($enomAccount,Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::status(App\Models\Server::findOrFail($enom->server_id),$enomAccount)),
    ...Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::renewals((int)$enom->server_id)],null,'label');
foreach(['TLD prices','Invoice issued','Invoice due'] as $label){expect($rows[$label]['value']!==null&&$rows[$label]['cause']===null,'No Missing cause under a set value: '.$label);}
expect(!str_contains($html,'Saved invoice days are invalid'),'Valid invoice days show no invalid-days cause');
$pause=$page->mountAction('processing_'.$enom->server_id)->assertActionMounted('processing_'.$enom->server_id)->instance()->getMountedAction();
same('Pause orders?',$pause->getModalHeading(),'Pause orders dialog heading');same('New registrar requests on this account wait until you resume.',$pause->getModalDescription(),'Pause orders dialog says what happens');
// System checks: failing checks first in danger or warning with their cause; passing checks collapse.
// Service listeners present, invoice-paid listener gone: paid orders wait for the scheduler.
foreach(['saving','updating','updated'] as $event){Illuminate\Support\Facades\Event::listen('eloquent.'.$event.': '.App\Models\Service::class,static fn()=>null);}
Illuminate\Support\Facades\Event::forget(App\Events\Invoice\Paid::class);
$flat=$page->instance()->content(\Filament\Schemas\Schema::make($page->instance()))->getFlatComponents();
$check=static fn(string $label)=>array_values(array_filter($flat,fn($e)=>$e instanceof \Filament\Infolists\Components\TextEntry&&$e->getLabel()===$label))[0];
same('Delayed',$check('Payment events')->getState(),'Missing paid listener delays paid orders');
same('warning',$check('Payment events')->getColor('Delayed'),'Delayed payment events warn');
same('Not running',$check('Background worker')->getState(),'No worker run recorded');
same('danger',$check('Background worker')->getColor('Not running'),'A stopped worker is danger');
// Domain monitoring on with a queue timeout under 180 seconds: the Background worker row names the cause, once.
app()->instance(Paymenter\Extensions\Servers\Domains\Services\WatchWork::class,new Paymenter\Extensions\Servers\Domains\Services\WatchWork(new Paymenter\Extensions\Servers\Domains\Dns\NativeDnsReader(static fn()=>[]),new Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryWork(static fn()=>null),true));
$queue=config('queue');config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>60]);
$short=Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id]);
$worker=array_values(array_filter($short->instance()->content(\Filament\Schemas\Schema::make($short->instance()))->getFlatComponents(),fn($e)=>$e instanceof \Filament\Infolists\Components\TextEntry&&$e->getLabel()==='Background worker'))[0];
same('Queue timeout too short',$worker->getState(),'Short queue timeout shows on the Background worker row');same('danger',$worker->getColor('Queue timeout too short'),'Short queue timeout is danger');
$text=html_entity_decode(strip_tags($short->html()),ENT_QUOTES);
same(1,substr_count($text,'Queue timeout too short'),'Queue timeout said once');same(1,substr_count($text,"Domain monitoring can't run. Use an asynchronous queue with retry_after of 180 seconds or more."),'Its cause said once');
config(['queue.connections.database.retry_after'=>180]);Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertDontSee('Queue timeout too short')->assertSee('Not running');
config(['queue'=>$queue]);app()->forgetInstance(Paymenter\Extensions\Servers\Domains\Services\WatchWork::class);
// Open domain alerts: a count row per account that links to the Domains list filtered to those domains.
same('None',$check('Domain alerts')->getState(),'No open domain alerts');
$alerted=Domain::where('sld','registrars-due-placeholder')->sole();
Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($alerted,'native_identity_missing',time());
$alerts=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id]);
$row=array_values(array_filter($alerts->instance()->content(\Filament\Schemas\Schema::make($alerts->instance()))->getFlatComponents(),fn($e)=>$e instanceof \Filament\Infolists\Components\TextEntry&&$e->getLabel()==='Domain alerts'))[0];
same(['1 domain','warning'],[$row->getState(),$row->getColor('1 domain')],'One domain with an open alert is a warning');
$filtered=Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::getUrl('index',['filters'=>['server_id'=>['value'=>(string)$enom->server_id],'alerts'=>['isActive'=>true]]]);
$alerts->assertActionVisible(TestAction::make('domain_alerts')->schemaComponent('system_checks','content'))->assertSee('View domains')->assertSee('See the Alert column. Open a domain for more causes.')->assertSeeHtml('href="'.e($filtered).'"');
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$silo->server_id])->assertDontSee('View domains');
// The same alert on a domain whose service still exists: the Service row keeps its value and link, with the cause below in danger.
Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>App\Admin\Resources\ServiceResource::registerRoutes($panel));app('router')->getRoutes()->refreshNameLookups();
$serviceUrl=App\Admin\Resources\ServiceResource::getUrl('edit',['record'=>$service->id]);
$fact=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($alerted->fresh())['Service'];
same([$alerted->domain.' registration','Service #'.$service->id.' · Customer, order or service missing','danger',$serviceUrl],[$fact['value'],$fact['sub'],$fact['tone'],$fact['url']],'A live service keeps its value and link under the alert');
Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$alerted->id])
    ->assertSeeHtml('href="'.e($serviceUrl).'"')->assertSeeInOrder(['Service',$alerted->domain.' registration','Customer, order or service missing']);
$passing=array_values(array_filter($flat,fn($e)=>$e instanceof \Filament\Schemas\Components\Section&&$e->getHeading()==='Passing checks'))[0];
expect($passing->isCollapsed(),'Passing checks start collapsed');
$inside=array_map(fn($e)=>$e->getLabel(),array_filter($passing->getChildSchema()->getFlatComponents(),fn($e)=>$e instanceof \Filament\Infolists\Components\TextEntry));
expect(in_array('Domain editor',$inside,true)&&!in_array('Payment events',$inside,true)&&!in_array('Background worker',$inside,true),'Only passing checks collapse');
// Public suffix list row: shown with the list permission; never downloaded is a failing check with Download now and Change source.
$page->assertDontSee('Public suffix list');
Paymenter\Extensions\Servers\Domains\Services\PslSchema::install();Paymenter\Extensions\Servers\Domains\Services\PslReviewSchema::install();
$psl=Livewire::actingAs(staffUser([Staff::VIEW,PslPage::VIEW,PslPage::MANAGE]))->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('Public suffix list')->assertSee('Not downloaded')
    ->assertActionVisible(TestAction::make('psl_download')->schemaComponent('system_checks','content'))->assertActionVisible(TestAction::make('psl_source')->schemaComponent('system_checks','content'))
    ->assertActionDoesNotExist(TestAction::make('psl_accept')->schemaComponent('system_checks','content'));
// The full list page is off the menu: its System checks row links to it.
$psl->assertActionVisible(TestAction::make('psl_details')->schemaComponent('system_checks','content'))->assertSee('View details')->assertSeeHtml('href="'.PslPage::getUrl().'"');
$flat=$psl->instance()->content(\Filament\Schemas\Schema::make($psl->instance()))->getFlatComponents();
same('danger',array_values(array_filter($flat,fn($e)=>$e instanceof \Filament\Infolists\Components\TextEntry&&$e->getLabel()==='Public suffix list'))[0]->getColor('Not downloaded'),'A list never downloaded is danger');
Livewire::actingAs(staffUser([Staff::VIEW,PslPage::VIEW]))->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('Not downloaded')
    ->assertActionDoesNotExist(TestAction::make('psl_download')->schemaComponent('system_checks','content'))->assertActionDoesNotExist(TestAction::make('psl_source')->schemaComponent('system_checks','content'));
$capAccount=Account::where('server_id',$enom->server_id)->sole();
$capFacts=Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::class;
same('0 of 1000',$capFacts::calls($capAccount)['value'],'Absent setting uses the default limit of 1000');
$capKey=EnomTestSendLimit::account((string)$capAccount->stable_identity);DB::table(EnomTestSendLimitSchema::ACCOUNTS)->insert(['account'=>$capKey]);
DB::table(EnomTestSendLimitSchema::SENDS)->insert(array_fill(0,50,['account'=>$capKey,'command'=>'GetBalance','sent_at'=>$clock->now()->getTimestamp()-3600]));
config(['domains.enom_test_sends_per_day'=>50]);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('50 of 50')->assertSee('Limit reached. Try again later.');
same('50 of 50',$capFacts::calls($capAccount)['value'],'Sends from the last 24 hours fill the limit');
config(['domains.enom_test_sends_per_day'=>0]);same('50 of 0',$capFacts::calls($capAccount)['value'],'Zero explicitly refuses all test calls');
foreach([[0,60],[50,0]] as [$dayLimit,$minuteLimit]){
    config(['domains.enom_test_sends_per_day'=>$dayLimit,'domains.enom_test_sends_per_minute'=>$minuteLimit]);
    same('The Enom test send limit is 0, so no Enom test requests are sent.',$capFacts::calls($capAccount)['sub'],'Zero limit explains that waiting cannot restore capacity');
    Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('The Enom test send limit is 0, so no Enom test requests are sent.')->assertDontSee('Limit reached. Try again later.');
}
config(['domains.enom_test_sends_per_day'=>0,'domains.enom_test_sends_per_minute'=>60]);
$beforeCap=[DB::table('domains_operations')->count(),DB::table(EnomTestSendLimitSchema::SENDS)->count()];
foreach(['many',-1] as $invalidCap){
    config(['domains.enom_test_sends_per_day'=>$invalidCap]);
    same('The Enom test send limit in server config is invalid, so no Enom test requests are sent. Ask your server administrator to fix it.',$capFacts::calls($capAccount)['sub'],'Invalid limit explains refusal');
}
same($beforeCap,[DB::table('domains_operations')->count(),DB::table(EnomTestSendLimitSchema::SENDS)->count()],'Reading bad limit facts changes no requests or counts');
config(['domains.enom_test_sends_per_day'=>50]);
Illuminate\Support\Facades\Schema::rename(EnomTestSendLimitSchema::SENDS,'domains_test_sends_saved');
same("Couldn't read the Enom test send count, so Enom test requests are refused. Try again.",$capFacts::calls($capAccount)['sub'],'Unreadable count has its own cause');
Illuminate\Support\Facades\Schema::rename('domains_test_sends_saved',EnomTestSendLimitSchema::SENDS);
$liveCap=clone $capAccount;$liveCap->environment='live';same(null,$capFacts::calls($liveCap),'Live account omits the TEST cap');

config(['domains.enom_test_sends_per_day'=>'many']);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('Missing')->assertSee('The Enom test send limit in server config is invalid, so no Enom test requests are sent. Ask your server administrator to fix it.');
config(['domains.enom_test_sends_per_day'=>1000]);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$silo->server_id])->assertSee('NameSilo test account')->assertSee('Missing')->assertSee('Not checked yet')
    ->assertSee('Add funds')->assertSeeHtml('https://ote.namesilo.com/cart/add-funds')->assertDontSee('API calls, last 24 hours');
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
// Pause orders, then the Resume orders button and dialog (runtime-worker covers the resume write on a full install).
Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])
    ->callAction('processing_'.$enom->server_id,['confirmed'=>true,'reason'=>'Synthetic pause check'])->assertNotified('Orders paused');
Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('Orders paused')->assertSee('Resume orders')->assertDontSee('Pause orders')
    ->mountAction('processing_'.$enom->server_id)->assertActionMounted('processing_'.$enom->server_id)
    ->tap(fn($t)=>same(['Resume orders?','Registrar requests on this account start sending again.'],[$t->instance()->getMountedAction()->getModalHeading(),$t->instance()->getMountedAction()->getModalDescription()],'Resume orders dialog'));
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>999999])->assertNotFound();
Livewire::actingAs(staffUser([]))->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertForbidden();
// Corrupt saved identity must leave both modes and credential repair reachable.
$savedIdentity=$enom->getRawOriginal('stable_identity');
$liveBinding=new AccountBinding('enom',(int)$enom->server_id,Environment::Live,$enom->stable_identity);
$missingIdentity='Missing: saved account identity cannot be read. Restore the saved identity before accepting provider actions.';
DB::table('domains_accounts')->where('id',$enom->id)->update(['stable_identity'=>'broken ciphertext']);
foreach(['test','live'] as $mode){
    DB::table('domains_accounts')->where('id',$enom->id)->update(['environment'=>$mode]);
    config(['domains.enom_identity.environment'=>$mode,'domains.enom_writes.registration'=>true,'domains.enom_live.renewal'=>true,'domains.enom_live.management'=>true]);
    $page=Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSuccessful()
        ->assertActionVisible(setupAction('configure',(int)$enom->server_id));
    foreach(['live_renewal','live_management'] as $name){
        $page->assertActionDoesNotExist(setupAction($name,(int)$enom->server_id));
    }
    $action=setupAction('acceptance',(int)$enom->server_id);
    $page->mountAction($action)->assertActionMounted($action);
    $schema=(new ReflectionMethod(RegistrarAccount::class,'getMountedActionSchema'))->invoke($page->instance());
    $dialog=html_entity_decode(strip_tags($schema->toHtml()),ENT_QUOTES);
    same(1,substr_count($dialog,$missingIdentity),'One cause next to the omitted control');
    $names=array_map(fn($field)=>$field->getName(),$schema->getFlatFields());
    expect(!in_array($mode.'_registration_accepted',$names,true),'Unreadable '.$mode.' binding omits provider acceptance');
    expect(in_array('ending',$names,true)&&in_array('reason',$names,true),'Independent controls remain editable');
}
foreach(['live_renewal','live_management'] as $name){
    $action=$name.'_'.$enom->server_id;
    $page=Livewire::actingAs($admin)->test(RegistrarAccountLiveActionsTestPage::class,['account'=>$enom->server_id])->assertSuccessful()
        ->assertActionVisible($action)->mountAction($action)->assertActionMounted($action);
    $schema=(new ReflectionMethod(RegistrarAccount::class,'getMountedActionSchema'))->invoke($page->instance());
    $dialog=html_entity_decode(strip_tags($schema->toHtml()),ENT_QUOTES);
    same(1,substr_count($dialog,$missingIdentity),'Unreadable binding shows the full warning once: '.$name);
    $names=array_map(fn($field)=>$field->getName(),$schema->getFlatFields());
    foreach(['accept','verified'] as $field){expect(!in_array($field,$names,true),'Unreadable binding omits '.$field.': '.$name);}
    expect(in_array('reason',$names,true),'Unreadable binding keeps the independent reason: '.$name);
}
// A live fingerprint must match the restored identity and live environment.
DB::table('domains_accounts')->where('id',$enom->id)->update(['stable_identity'=>$savedIdentity,'account_fingerprint'=>$liveBinding->fingerprint,
    'identity_fingerprint'=>hash('sha256',json_encode(['enom','live',$enom->stable_identity],JSON_THROW_ON_ERROR))]);
same(Environment::Live,CatalogueAcceptances::binding(Account::findOrFail($enom->id))->environment,'Healthy inverse uses a valid live binding');
same($liveBinding->fingerprint,CatalogueAcceptances::binding(Account::findOrFail($enom->id))->fingerprint,'Healthy inverse fingerprint matches live identity');
foreach(['live_renewal','live_management'] as $name){
    $action=$name.'_'.$enom->server_id;
    $page=Livewire::actingAs($admin)->test(RegistrarAccountLiveActionsTestPage::class,['account'=>$enom->server_id])->assertSuccessful()
        ->assertActionVisible($action)->mountAction($action)->assertActionMounted($action);
    $schema=(new ReflectionMethod(RegistrarAccount::class,'getMountedActionSchema'))->invoke($page->instance());
    $dialog=html_entity_decode(strip_tags($schema->toHtml()),ENT_QUOTES);
    same(0,substr_count($dialog,$missingIdentity),'Healthy live binding omits the warning: '.$name);
    $names=array_map(fn($field)=>$field->getName(),$schema->getFlatFields());
    foreach(['accept','verified','reason'] as $field){expect(in_array($field,$names,true),'Healthy live binding keeps '.$field.': '.$name);}
}
echo "Registrars list and account page passed\n";$nativeSuccessful=true;
