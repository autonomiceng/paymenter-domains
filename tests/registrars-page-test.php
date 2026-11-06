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
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{ActivationStatus,RegistrarAccount,RegistrarBalances as BalancesPage,RegistrarHistory,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, BalanceReads, BalanceSchema, ContactDetails, EnomTestSendLimit, EnomTestSendLimitSchema, ObservationSchema, RegistrarBalances, ReminderSchedules, ReminderSchema, RenewalBillingSettings, StaffSettingsReview};
extract(commerceFixture(true));$clock=new Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now);
app()->instance(AccountBindings::class,$bindings);app()->instance(Clock::class,$clock);app()->instance(RegistrarBalances::class,new RegistrarBalances($bindings,$clock,new Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock)));
$enom=Account::where('server_id',$tlds[0]->server_id)->sole();$silo=Account::where('server_id',$tlds[1]->server_id)->sole();
App\Models\Server::whereKey($enom->server_id)->update(['name'=>'Enom test account']);App\Models\Server::whereKey($silo->server_id)->update(['name'=>'NameSilo test account']);
// Native checkout still creates invoices and dispatches every admission/binding event. Only the
// unrelated PDF attachment renderer uses Paymenter's supported document-extension event here.
Illuminate\Support\Facades\Event::listen(App\Events\Invoice\GeneratePdf::class,static function(App\Events\Invoice\GeneratePdf $event):void {
    $event->setPdfFromContent("%PDF-1.4\n% Synthetic registrar-page invoice attachment.\n%%EOF\n");
});
$user=commerceUser();$product=$native->product($tlds[0],'register');
// This page fixture needs real checkout, not a separate credit-checkbox render. Livewire's
// native update batches that property and the checkout call, as one browser request does.
$checkout=static function(App\Models\Cart $cart)use($user){
    Illuminate\Support\Once::flush();
    $params=$user->properties()->whereIn('key',['address','city','zip','country','phone'])->distinct()->count('key')===5
        ? [] : [['contact'=>commerceOrderContact($user),'save_account'=>false]];
    return Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Cart::class)
        ->update(calls:[['method'=>'checkout','params'=>$params,'path'=>'']],updates:['use_credits'=>false]);
};
// The balance forecast needs real service/domain bindings; native checkout exercises the admission and invoice hooks.
// Synthetic offer composition keeps registrar deployment and physical sends outside this page fixture.
$eligibility=new class($bindings,$clock) extends \Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility {
    public \Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers $fixtureOffers;
    protected function catalogue(): ?\Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers {return $this->fixtureOffers;}
};$eligibility->fixtureOffers=$offers;
$admission=new class($native,$sellers,$eligibility,$clock) extends \Paymenter\Extensions\Servers\Domains\Services\CartAdmission {
    public \Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers $fixtureOffers;
    protected function catalogue(): ?\Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers {return $this->fixtureOffers;}
};$admission->fixtureOffers=$offers;$admission->boot();
$invoiceBinding=new \Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding($admission,$native,$prices,$clock);
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class,$invoiceBinding);$invoiceBinding->boot();
$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('registrars-due-placeholder.com'));
$checkout($cart)->assertHasNoErrors();gc_collect_cycles();gc_mem_caches();
$service=App\Models\Service::where('user_id',$user->id)->sole();$service->update(['status'=>'active']);
// One renewal due within 30 days at registrar cost 8.00; the balance is one cent short.
$domain=Domain::where('service_id',$service->id)->sole();
DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>'active','provider_expires_at'=>'2026-11-10']);
BalanceReads::record(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($enom),new Money('7.99','USD'),$clock->now(),'manual');
$facts=RegistrarBalances::facts($enom,$clock->now());
same('USD 7.99',$facts['balance'],'Staff money puts the currency first');same('Low balance',$facts['status'],'Balance below Due in 30 days is Low balance');
same('USD 8.00',$facts['total'],'Due in 30 days');same('Oct 18, 2026 12:00 UTC',$facts['checked'],'Checked date in staff format');same(null,$facts['cause'],'A read balance has no cause');
$siloFacts=RegistrarBalances::facts($silo,$clock->now());same(null,$siloFacts['balance'],'Unread balance is Missing');same('Not checked yet. Use Check balance.',$siloFacts['cause'],'Missing balance names its cause');
same('https://ote.namesilo.com/cart/add-funds',RegistrarBalances::fundsUrl('namesilo','test'),'NameSilo Test funds the sandbox account');
same('https://www.namesilo.com/cart/add-funds',RegistrarBalances::fundsUrl('namesilo','live'),'NameSilo Live funds the live account');
same('https://cp.enom.com/',RegistrarBalances::fundsUrl('enom','test'),'Enom funding home');
// A server without an account, a disabled server and a seeded demo account.
$empty=App\Models\Server::create(['name'=>'Spare account','extension'=>'Domains','type'=>'server','enabled'=>true]);
$off=App\Models\Server::create(['name'=>'Old account','extension'=>'Domains','type'=>'server','enabled'=>false]);
$demoId=(int)App\Models\Server::max('id')+1;fixtureAccount($demoId,'namesilo','demo-namesilo');$demo=Account::where('server_id',$demoId)->sole();
App\Models\Server::whereKey($demo->server_id)->update(['name'=>'Demo NameSilo']);
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
staffPanel();$panel=Filament::getCurrentPanel();$pages=[RegistrarSetup::class,RegistrarAccount::class,RegistrarHistory::class,BalancesPage::class,ActivationStatus::class,PslPage::class];$panel->pages($pages);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel,$pages){foreach($pages as $page){$page::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$staff=staffUser([Staff::VIEW]);Auth::setUser($staff);
$sync=Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::lastSync((int)$enom->server_id);
expect($sync!==null,'Last sync uses the saved registrar price read even without a runtime tick');
\Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::verify();
DB::table('domains_runtime_turns')->insert(['server_id'=>$enom->server_id,'account_fingerprint'=>$enom->account_fingerprint,'finished_at'=>$clock->now()->getTimestamp()+60]);
same($sync,Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::lastSync((int)$enom->server_id),'A worker tick without a read does not change Last sync');
DB::table('domains_runtime_turns')->where('server_id',$enom->server_id)->delete();
config(['settings.credits_enabled'=>false]);
$credits=collect(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::system()['failing'])->firstWhere('label','Customer credits');
expect($credits!==null&&str_contains((string)$credits['cause'],'Turn on Credits in Settings.'),'Hidden Credits page warns with the setting to change');
expect(str_contains((string)$credits['cause'],'Customer credits are off.') && str_contains((string)$credits['cause'],'account balance') && !str_contains((string)$credits['cause'],'Refunds stay'), 'Credits off explains the setting and refund destination without implying a refund failed');
config(['settings.credits_enabled'=>true]);
same(null,collect(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::system()['failing'])->firstWhere('label','Customer credits'),'Warning clears when customers can use Credits');


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
// Release the completed table before building the account forms.
unset($list,$rows,$cell);gc_collect_cycles();gc_mem_caches();
// Account page.
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSuccessful();
foreach(['Enom test account','Active','Check balance','Account','Registrar','Enom','Mode','Test','Balance','USD 7.99','Low balance','Add funds','Due in 30 days','USD 8.00',
    'Domains','API calls, last 24 hours','0 of 1,000'] as $needle){$page->assertSee($needle);}
$html=$page->html();
foreach(['https://cp.enom.com/','Registrars'] as $needle){expect(str_contains($html,$needle),'Account page: '.$needle);}
foreach(['Server #','Unknown','Enom TEST daily calls','Verified user ID','Retained domains','Environment',"\u{2014}"] as $needle){expect(!str_contains($html,$needle),'Account page never shows: '.$needle);}
// System checks, renewal section and header actions for staff who can change settings.
ReminderSchedules::enroll((int)$enom->server_id);
$admin=staffUser([Staff::VIEW,Staff::POLICY,...array_map([StaffSettingsReview::class,'permission'],array_keys(StaffSettingsReview::ACTIONS))]);
// Setup buttons show only when they can run: bind a writer that can reach the registrar.
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites::class,new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites(app(\Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::class),app(\Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class),app(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class),true));
$page=Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSuccessful();
// The History tab must present rows, not JSON-serialized objects.
$review=new StaffSettingsReview($admin->id,(int)$enom->server_id,null,'acceptance',
    StaffSettingsReview::ticket($admin->id,(int)$enom->server_id,null,'acceptance',StaffSettingsReview::stamp((int)$enom->server_id,null)),
    'Reviewed <strong>catalogue</strong>');
$review->local(static fn()=>null);
expect(Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::history((int)$enom->server_id,(int)$tlds[0]->id)!==null,'TLD History reads both catalogue observations and staff review rows');
$history=Livewire::actingAs($admin)->test(RegistrarHistory::class,['account'=>$enom->server_id])->assertSuccessful();
foreach(['Time','Change','Detail','By','Sales settings changed','Reviewed &lt;strong&gt;catalogue&lt;/strong&gt;'] as $needle){
    expect(str_contains($history->html(),$needle),'History tab renders readable escaped rows: '.$needle);
}
$history->searchTable('no such change')->assertDontSee('Sales settings changed')->assertSee('No changes match')->assertSee('Clear the search or filters to see all rows.')->assertDontSee('Changes show here');
$detail=$history->instance()->getTable()->getColumn('reason');same([true,2],[$detail->canWrap(),$detail->getLineClamp()],'History Detail wraps to 2 lines');
same('Changes show here as they happen.',Livewire::actingAs($admin)->test(RegistrarHistory::class,['account'=>$empty->id])->assertSee('No changes yet')->instance()->getTable()->getEmptyStateDescription(),'An empty history says what fills it');
unset($history,$detail);gc_collect_cycles();gc_mem_caches();
// Dialog help is a button keyboard users can reach, and a click can't open another action over the dialog.
$dialog=Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->mountAction(setupAction('configure',(int)$enom->server_id));
expect(preg_match('/<button\b[^>]*x-tooltip[^>]*>/s',$dialog->instance()->getSchema($dialog->instance()->getMountedActionSchemaName())->toHtml(),$help)===1,'Dialog help renders as a button with a tooltip');
expect(!str_contains($help[0],'wire:click'),'Help button runs nothing');
unset($dialog);gc_collect_cycles();gc_mem_caches();

$reminderAction=TestAction::make('reminders_'.$enom->server_id)->schemaComponent('account_'.$enom->server_id,'content');
$beforeInvalidReason=(array)DB::table('domains_reminder_schedules')->where('server_id',$enom->server_id)->sole();
$page->callAction($reminderAction,['reason'=>"Reviewed\u{2029}reminders"])->assertHasActionErrors(['reason'=>'regex']);
same($beforeInvalidReason,(array)DB::table('domains_reminder_schedules')->where('server_id',$enom->server_id)->sole(),'Reminder reason field error changes no schedule');
$page->unmountAction();
// The switch keeps the reminder revision check: a page left open can't undo a newer change.
$schedule=static fn()=>DB::table('domains_reminder_schedules')->where('server_id',$enom->server_id)->sole();$saved=$schedule();
$switch=TestAction::make('reminders_switch_'.$enom->server_id)->schemaComponent('account_'.$enom->server_id,'content')->arguments(['revision'=>(int)$saved->revision]);
$stale=Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSeeHtml('role="switch"')->assertSeeHtml('aria-label="Reminder emails"');
Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->callAction($switch)->assertNotified('Reminders saved');
same(!(bool)$saved->enabled,(bool)$schedule()->enabled,'One click flips reminders');
$stale->callAction($switch)->assertNotified("Couldn't save reminders");same(!(bool)$saved->enabled,(bool)$schedule()->enabled,'A stale switch changes nothing');
DB::table('domains_reminder_schedules')->where('server_id',$enom->server_id)->update((array)$saved);
unset($stale);gc_collect_cycles();gc_mem_caches();
foreach(['Account','API connection','OK','Test connection','Balance and usage','Sales','What you sell','.com','TLD prices','Registrations','Transfers (Experimental)','Renewals','Domain settings','DNS, nameservers and contacts',
    'Orders','Invoice issued','21 days before expiry','Invoice due','7 days before expiry','Reminder emails','Reminder schedule',
    '60, 30, 7 and 1 days before, 3 days after','Customer can renew early','More','Edit API credentials','History',
    'System checks','Background worker','Not running','Payment events'] as $needle){$page->assertSee($needle);}
foreach(['Connected','Working','View price history'] as $needle){$page->assertDontSee($needle);}
$html=$page->html();
foreach(['Catalogue acceptance','Account binding','Worker cadence','Native server settings','Registrar setup','(defaults)','Read capacity','Runtime account','Notification safety',
    'Verify identity','Read balance','Enable processing','Pause processing','Missing Paymenter records','Transfer watch relinks','Start after payment','Change recorded','Confirm'] as $needle){
    expect(!str_contains($html,$needle),'Account page never shows: '.$needle);}
// Legacy fixtures have no authenticated local NameSilo runtime. They cannot acquire send permission.
config(['domains.namesilo_test.management'=>false,'domains.namesilo_test.renewal'=>false]);
$siloAccount=Account::where('server_id',$silo->server_id)->sole();$siloServer=(int)$silo->server_id;
$siloRows=static fn()=>array_column(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup($siloAccount->fresh(),['label'=>'Active','reason'=>null]),null,'label');
foreach(['test','live'] as $mode){
    $modeBinding=new AccountBinding('namesilo',$siloServer,Environment::from($mode),$siloAccount->stable_identity);
    DB::table('domains_accounts')->where('id',$siloAccount->id)->update(['environment'=>$mode,'account_fingerprint'=>$modeBinding->fingerprint,
        'identity_fingerprint'=>hash('sha256',json_encode(['namesilo',$mode,$siloAccount->stable_identity],JSON_THROW_ON_ERROR))]);
    $rows=$siloRows();
    same(['Not available yet',null],[$rows['DNS, nameservers and contacts']['value'],$rows['DNS, nameservers and contacts']['action']],$mode.' without admitted runtime stays closed');
    same(['Not available yet',null],[$rows['Renewals']['value'],$rows['Renewals']['action']],$mode.' without sending cannot enable billing');
    Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$silo->server_id])->assertSee('Free when supported by the TLD.')->assertSee('Late renewal is not offered for NameSilo.');
}
DB::table('domains_accounts')->where('id',$siloAccount->id)->update(['environment'=>'test','account_fingerprint'=>$silo->account_fingerprint,'identity_fingerprint'=>$silo->identity_fingerprint]);

$reminderQuery=DB::table('domains_reminder_schedules')->where('server_id',$enom->server_id);$savedReminder=(array)(clone $reminderQuery)->sole();$reminderQuery->update(['enabled'=>true]);
$reminderPage=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id]);
foreach([[[1],'1 day before, 1 day after'],[[0],'0 days before, 0 days after'],[[2],'2 days before, 2 days after'],[[1,2],'2 and 1 days before, 1 and 2 days after'],[[],null]] as [$days,$expected]){
    $reminderQuery->update(['before_days'=>json_encode($days),'after_days'=>json_encode($days)]);
    same($expected??'No days set',Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderFacts::scheduleRow((int)$enom->server_id)['value'],'Reminder day grammar: '.json_encode($days));
    if($expected!==null){$reminderPage->call('$refresh')->assertSee($expected);}
}
unset($reminderPage);gc_collect_cycles();gc_mem_caches();
$reminderQuery->update(['before_days'=>'{']);
same("Saved reminder days can't be read. Restore them from a backup.",Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderFacts::emails((int)$enom->server_id)['cause'],'Unreadable reminders retain their cause');
$reminderQuery->update($savedReminder);Auth::setUser($admin);

// A cause shows under a value only when there is a problem: a valid invoice day has none.
$enomAccount=Account::where('server_id',$enom->server_id)->sole();
$rows=array_column([...Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup($enomAccount,Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::status(App\Models\Server::findOrFail($enom->server_id),$enomAccount)),
    ...Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::renewals((int)$enom->server_id)],null,'label');
foreach(['Invoice issued','Invoice due'] as $label){expect($rows[$label]['value']!==null&&$rows[$label]['cause']===null,'No Missing cause under a set value: '.$label);}
expect(!str_contains($html,'Saved invoice days are invalid'),'Valid invoice days show no invalid-days cause');
$pause=$page->mountAction(setupAction('processing',(int)$enom->server_id))->instance()->getMountedAction();
same(['Turn off orders?','New orders and renewals wait until you turn this back on.'],[$pause->getModalHeading(),$pause->getModalDescription()],'Stopping orders says what happens in one sentence');$page->unmountAction();
// System checks: failing checks first in danger or warning with their cause, then passing checks.
// Service listeners present, invoice-paid listener gone: paid orders wait for the scheduler.
foreach(['saving','updating','updated'] as $event){Illuminate\Support\Facades\Event::listen('eloquent.'.$event.': '.App\Models\Service::class,static fn()=>null);}
Illuminate\Support\Facades\Event::forget(App\Events\Invoice\Paid::class);
$checks=static fn($page)=>array_column(array_merge(...array_values($page->instance()->checks())),null,'label');
$check=static fn(string $label)=>$checks($page)[$label];
$page->assertSeeHtml('class="domains-trace')->assertSeeHtml('role="listitem"');
same(['Delayed','warning'],[$check('Payment events')['value'],$check('Payment events')['tone']],'Missing paid listener delays paid orders');
same(['Not running','danger'],[$check('Background worker')['value'],$check('Background worker')['tone']],'A stopped worker is danger');
// Domain monitoring on with a queue timeout under 180 seconds: the Background worker row names the cause, once.
app()->instance(Paymenter\Extensions\Servers\Domains\Services\WatchWork::class,new Paymenter\Extensions\Servers\Domains\Services\WatchWork(new Paymenter\Extensions\Servers\Domains\Dns\NativeDnsReader(static fn()=>[]),new Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryWork(static fn()=>null),true));
$queue=config('queue');config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>60]);
$short=Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id]);
$worker=$checks($short)['Background worker'];
same(['Queue timeout too short','danger'],[$worker['value'],$worker['tone']],'Short queue timeout shows on the Background worker row');
$text=html_entity_decode(strip_tags($short->html()),ENT_QUOTES);
same(1,substr_count($text,'Queue timeout too short'),'Queue timeout said once');same(1,substr_count($text,"Monitoring can't run. Ask your site admin to set the queue timeout to 180 seconds or more."),'Its cause said once');
config(['queue.connections.database.retry_after'=>180]);Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertDontSee('Queue timeout too short')->assertSee('Not running');
config(['queue'=>$queue]);app()->forgetInstance(Paymenter\Extensions\Servers\Domains\Services\WatchWork::class);
unset($short,$worker);gc_collect_cycles();gc_mem_caches();
// Open domain alerts: a count row per account that links to the Domains list filtered to those domains.
same('OK',$check('Domain alerts')['value'],'No open domain alerts');
$alerted=Domain::where('sld','registrars-due-placeholder')->sole();
Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($alerted,'native_identity_missing',time());
$alerts=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id]);
$row=$checks($alerts)['Domain alerts'];
same(['1','warning'],[$row['value'],$row['tone']],'One domain with an open alert is a warning');
$filtered=Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::getUrl('index',['filters'=>['server_id'=>['value'=>(string)$enom->server_id],'alerts'=>['isActive'=>true]]]);
$alerts->assertActionVisible(TestAction::make('domain_alerts')->schemaComponent('system_checks','content'))->assertSee('View domains')->assertSeeHtml('href="'.e($filtered).'"');
same([],$checks(Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$silo->server_id]))['Domain alerts']['buttons']??[],'No alerts, no link to them');
unset($alerts,$row);gc_collect_cycles();gc_mem_caches();
// The same alert on a domain whose service still exists: the Service row keeps its value and link, with the cause below in danger.
Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>App\Admin\Resources\ServiceResource::registerRoutes($panel));app('router')->getRoutes()->refreshNameLookups();
$serviceUrl=App\Admin\Resources\ServiceResource::getUrl('edit',['record'=>$service->id]);
$fact=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($alerted->fresh())['Service'];
same([$alerted->domain.' registration','Service #'.$service->id.". Customer, order or service missing. The name stays reserved. Don't restore records by hand.",'danger',$serviceUrl],[$fact['value'],$fact['sub'],$fact['tone'],$fact['url']],'A live service keeps its value and link under the alert');
Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$alerted->id])
    ->assertSeeHtml('href="'.e($serviceUrl).'"')->assertSeeInOrder(['Service','registration','Customer, order or service missing']);
$groups=$page->instance()->checks();
expect(!str_contains(json_encode($groups),'Domain editing is ready'),'A site check does not promise edits a registrar account can still block');
expect(in_array('Domain editor',array_column($groups['passing'],'label'),true)&&in_array('Payment events',array_column($groups['failing'],'label'),true),'Failing checks stay apart from passing checks');
// Public suffix list row: shown with the list permission; never downloaded is a failing check with Download now and Change source.
$page->assertDontSee('Public suffix list');
// The full fixture installation already owns the suffix-list schema.
$psl=Livewire::actingAs(staffUser([Staff::VIEW,PslPage::VIEW,PslPage::MANAGE]))->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('Public suffix list')->assertSee('Not downloaded')
    ->assertActionDoesNotExist(TestAction::make('psl_download')->schemaComponent('system_checks','content'))->assertActionDoesNotExist(TestAction::make('psl_source')->schemaComponent('system_checks','content'))
    ->assertActionDoesNotExist(TestAction::make('psl_accept')->schemaComponent('system_checks','content'));
$psl->assertDontSee('View details')->assertDontSee('Download now')->assertSee('Updates retry automatically each week');
same('danger',$checks($psl)['Public suffix list']['tone'],'A list never downloaded is danger');
Livewire::actingAs(staffUser([Staff::VIEW,PslPage::VIEW]))->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('Not downloaded')
    ->assertActionDoesNotExist(TestAction::make('psl_download')->schemaComponent('system_checks','content'))->assertActionDoesNotExist(TestAction::make('psl_source')->schemaComponent('system_checks','content'));
// Release completed permission, reminder and system-check components, including closures that capture them.
unset($page,$pause,$check,$checks,$psl,$groups,$rows);gc_collect_cycles();gc_mem_caches();
$capAccount=Account::where('server_id',$enom->server_id)->sole();
$capFacts=Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::class;
same('0 of 1,000',$capFacts::calls($capAccount)['value'],'Absent setting uses the default limit of 1000');
$capKey=EnomTestSendLimit::account((string)$capAccount->stable_identity);DB::table(EnomTestSendLimitSchema::ACCOUNTS)->insert(['account'=>$capKey]);
DB::table(EnomTestSendLimitSchema::SENDS)->insert(array_fill(0,50,['account'=>$capKey,'command'=>'GetBalance','sent_at'=>$clock->now()->getTimestamp()-3600]));
config(['domains.enom_test_sends_per_day'=>50]);
$capPage=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('50 of 50')->assertSee('Limit reached. Try again later.');
same('50 of 50',$capFacts::calls($capAccount)['value'],'Sends from the last 24 hours fill the limit');
config(['domains.enom_test_sends_per_day'=>0]);same('50 of 0',$capFacts::calls($capAccount)['value'],'Zero explicitly refuses all test calls');
foreach([[0,60],[50,0]] as [$dayLimit,$minuteLimit]){
    config(['domains.enom_test_sends_per_day'=>$dayLimit,'domains.enom_test_sends_per_minute'=>$minuteLimit]);
    same('Our test send limit is 0, so no Enom test requests are sent.',$capFacts::calls($capAccount)['sub'],'Zero limit explains that waiting cannot restore capacity');
    $capPage->call('$refresh')->assertSee('Our test send limit is 0, so no Enom test requests are sent.')->assertDontSee('Limit reached. Try again later.');
}
config(['domains.enom_test_sends_per_day'=>0,'domains.enom_test_sends_per_minute'=>60]);
$beforeCap=[DB::table('domains_operations')->count(),DB::table(EnomTestSendLimitSchema::SENDS)->count()];
foreach(['many',-1] as $invalidCap){
    config(['domains.enom_test_sends_per_day'=>$invalidCap]);
    same('Our test send limit on this site is invalid, so no Enom test requests are sent. Ask your site admin to fix it.',$capFacts::calls($capAccount)['sub'],'Invalid limit explains refusal');
}
same($beforeCap,[DB::table('domains_operations')->count(),DB::table(EnomTestSendLimitSchema::SENDS)->count()],'Reading bad limit facts changes no requests or counts');
config(['domains.enom_test_sends_per_day'=>50]);
Illuminate\Support\Facades\Schema::rename(EnomTestSendLimitSchema::SENDS,'domains_test_sends_saved');
same("Couldn't read our test send count, so no Enom test requests are sent. Try again.",$capFacts::calls($capAccount)['sub'],'Unreadable count has its own cause');
Illuminate\Support\Facades\Schema::rename('domains_test_sends_saved',EnomTestSendLimitSchema::SENDS);
// A valid persisted Live binding must not fall back to TEST counts or limits when deployment is off.
$liveCapServer=App\Models\Server::create(['name'=>'Synthetic Live count','extension'=>'Domains','type'=>'server','enabled'=>true]);
$liveCap=Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$liveCapServer->id,'registrar_key'=>'enom','environment'=>'live','stable_identity'=>'live-count-uid']));
$identityEnvironment=config('domains.enom_identity.environment');config(['domains.enom_identity.environment'=>'test']);
$liveCapFacts=$capFacts::calls($liveCap);
same(null,$liveCapFacts['value'],'Unconfigured Live account never reports the TEST count or cap');
expect(str_contains($liveCapFacts['sub'],'Live account setup'),'Missing Live count or cap says what to fix');
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$liveCapServer->id])->assertSee('API calls, today (UTC)')->assertDontSee('API calls, last 24 hours');
config(['domains.enom_identity.environment'=>$identityEnvironment]);

config(['domains.enom_test_sends_per_day'=>'many']);
$capPage->call('$refresh')->assertSee('Missing')->assertSee('Our test send limit on this site is invalid, so no Enom test requests are sent. Ask your site admin to fix it.');
config(['domains.enom_test_sends_per_day'=>1000]);
unset($capPage);gc_collect_cycles();gc_mem_caches();
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$silo->server_id])->assertSee('NameSilo test account')->assertSee('Missing')->assertSee('Not checked yet')
    ->assertSee('Add funds')->assertSeeHtml('https://ote.namesilo.com/cart/add-funds')->assertDontSee('API calls, last 24 hours');
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$off->id])->assertSee('Not connected')->assertSee('Off in Paymenter')->assertDontSee('Add funds');
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$empty->id])->assertSee('Not connected')->assertDontSee('Check balance');
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$demo->server_id])->assertSee('Demo NameSilo')->assertSee('Demo');
// Orders paused: by staff, and by an unconfirmed paid request.
DB::table('domains_accounts')->where('server_id',$silo->server_id)->update(['processing_enabled'=>false]);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$silo->server_id])->assertSee('Orders paused')->assertSee('Paused by staff');
DB::table('domains_accounts')->where('server_id',$silo->server_id)->update(['processing_enabled'=>true]);
// Admit the second registrar's service too; retain its real request when arranging an uncertain result.
$siloBinding=AccountBindings::binding($silo->fresh());
$siloQuery=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($siloBinding,['com'],'register');
$ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::seller($siloBinding,'com',['register','transfer']),
    $ports->readOffers($siloQuery,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision);
$siloCart=commerceCart($user);commerceAdd($siloCart,$native->product($tlds[1],'register'),placeholderComFixtureName('registrars-held-placeholder.com'));
$checkout($siloCart)->assertHasNoErrors();gc_collect_cycles();gc_mem_caches();
$siloDomain=Domain::where('domain',placeholderComFixtureName('registrars-held-placeholder.com'))->sole();
$siloService=$siloDomain->service;$siloService->update(['status'=>'active']);
$held=Paymenter\Extensions\Servers\Domains\Models\Operation::where('service_id',$siloService->id)->sole();
DB::table('domains_operations')->where('id',$held->id)->update(['state'=>'uncertain']);$held->refresh();
$enomBinding=AccountBindings::binding($enom->fresh());
$enomQuery=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($enomBinding,['com'],'register');
$ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::seller($enomBinding,'com',['register','transfer']),
    $ports->readOffers($enomQuery,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision);
// A paid request waiting for the registrar shows on the Registrar requests row, never as the account's state.
Livewire::actingAs($staff)->test(RegistrarSetup::class)->assertDontSee('Request #'.$held->id.' unconfirmed');
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$silo->server_id])->assertSee('Registrar requests')->assertSee('1 under review');
// The Balance sits by its label on the account sheet; money right-aligns only in table cells.
$sheet=\Dom\HTMLDocument::createFromString(Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$silo->server_id])->html(),LIBXML_NOERROR);
$money=iterator_to_array($sheet->querySelectorAll('.domains-money'));
expect($money!==[]&&array_filter($money,fn($node)=>$node->closest('.fi-ta-cell')!==null)===[],'The Balance is money on the sheet, not in a table');
expect(preg_match('/(^|})\.domains-money\{[^}]*text-align/m',file_get_contents(dirname(__DIR__).'/Domains/resources/views/partials/staff-styles.blade.php'))===0,'Money right-aligns only in table cells, so the Balance stays by its label');
unset($sheet,$money);gc_collect_cycles();gc_mem_caches();
DB::table('domains_operations')->where('id',$held->id)->update(['state'=>'succeeded']);
same(['open'=>0,'staff'=>['on_hold'=>0,'under_review'=>0]],Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::requests($silo->server_id),'Finished requests leave the count');
DB::table('domains_operations')->where('id',$held->id)->update(['state'=>'uncertain']);
// Check balance: a failed read and the one-minute wait use the new toasts.
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->callAction('checkBalance')->assertNotified("Couldn't check balance");
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->callAction('checkBalance')->assertNotified('Wait a minute before checking again.');
// Pause orders, then the Resume orders button and dialog (runtime-worker covers the resume write on a full install).
Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])
    ->callAction(setupAction('processing',(int)$enom->server_id))->assertNotified('Orders paused');
same(false,(bool)Account::findOrFail($enom->id)->processing_enabled,'The switch needs no Reason or tick box');
Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('Orders paused')
    ->mountAction(setupAction('processing',(int)$enom->server_id))
    ->tap(fn($t)=>same(['Turn on orders?','New orders and renewals start going to the registrar.'],[$t->instance()->getMountedAction()->getModalHeading(),$t->instance()->getMountedAction()->getModalDescription()],'Resume orders dialog'));
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>999999])->assertNotFound();
Livewire::actingAs(staffUser([]))->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertForbidden();
// Corrupt saved identity must leave both modes and credential repair reachable.
$savedIdentity=$enom->getRawOriginal('stable_identity');
$liveBinding=new AccountBinding('enom',(int)$enom->server_id,Environment::Live,$enom->stable_identity);
DB::table('domains_accounts')->where('id',$enom->id)->update(['stable_identity'=>'broken ciphertext']);
foreach(['test','live'] as $mode){
    DB::table('domains_accounts')->where('id',$enom->id)->update(['environment'=>$mode]);
    config(['domains.enom_identity.environment'=>$mode,'domains.enom_writes.registration'=>true,'domains.enom_live.renewal'=>true,'domains.enom_live.management'=>true]);
    $page=Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSuccessful()
        ->assertActionVisible(setupAction('configure',(int)$enom->server_id));
    foreach(['live_renewal','live_management'] as $name){
        $page->assertActionDoesNotExist(setupAction($name,(int)$enom->server_id));
    }
    $page->assertSee('Restore the API connection first.');
    same(1,\Paymenter\Extensions\Servers\Domains\Services\BalanceForecast::for(Account::findOrFail($enom->id),$clock->now())['unknown'],'Unreadable identity keeps the due renewal cost unknown');
}
unset($page);gc_collect_cycles();gc_mem_caches();
// A live fingerprint must match the restored identity and live environment.
DB::table('domains_accounts')->where('id',$enom->id)->update(['stable_identity'=>$savedIdentity,'account_fingerprint'=>$liveBinding->fingerprint,
    'identity_fingerprint'=>hash('sha256',json_encode(['enom','live',$enom->stable_identity],JSON_THROW_ON_ERROR))]);
same(Environment::Live,\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::findOrFail($enom->id))->environment,'Healthy inverse uses a valid live binding');
same($liveBinding->fingerprint,\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::findOrFail($enom->id))->fingerprint,'Healthy inverse fingerprint matches live identity');
DB::table('domains_reminder_schedules')->where('server_id',$enom->server_id)->update(['account_fingerprint'=>$liveBinding->fingerprint]);
Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSuccessful()->assertDontSee('Restore the API connection first.');
// Unavailable features explain themselves; Transfers names the deployment setting needed to enable its switch.
$checklist=fn()=>array_column(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup(Account::findOrFail($enom->id),['label'=>'Active','reason'=>null]),null,'label');
$noKeys=function()use($checklist,$enom){foreach([...array_values($checklist()),...Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::renewals((int)$enom->server_id),...array_merge(...array_values(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::system())),Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::runtime(Account::findOrFail($enom->id))] as $row){
    expect($row['label']==='Transfers (Experimental)'||!preg_match('/domains\.|_[a-z]+_|site config/',($row['cause']??'').' '.($row['help']??'')),'No setting name in '.$row['label'].': '.($row['cause']??'').' '.($row['help']??''));expect($row['label']==='Reminder schedule'||($row['help']??'')!=='','Every row explains itself: '.$row['label']);}};
$site='https://billing.example.com';config(['app.url'=>$site,'domains.enom_live.site'=>$site,'domains.enom_live.catalogue'=>false,'domains.enom_live.accounts'=>[]]);
same(['Not available yet',null],[$checklist()['Renewals']['value'],$checklist()['Renewals']['action']],'Live with no opt-in shows no switch');same(['Not available yet',null],[$checklist()['Transfers (Experimental)']['value'],$checklist()['Transfers (Experimental)']['action']],'Live transfers with no deployment approval or saved opt-in show no switch');$noKeys();
config(['domains.enom_live.catalogue'=>true]);$noKeys();
config(['domains.enom_live.accounts'=>[$enom->stable_identity=>['fingerprint'=>$liveBinding->fingerprint]]]);DB::table('domains_accounts')->where('id',$enom->id)->update(['live_site'=>$site]);
// Verified Live credentials and a saved site do not replace current activation.
$bindings->configure((int)$enom->server_id,'enom',Environment::Live,['token'=>$enom->stable_identity]);
$liveEvidence=['fingerprint'=>$liveBinding->fingerprint,'site'=>$site,'confirmed_on'=>$clock->now()->format('Y-m-d'),
    'reference'=>'synthetic-live-orders','sha256'=>str_repeat('a',64),'currency'=>'USD','complete_history'=>true];
config(['domains.enom_live.accounts.'.$enom->stable_identity.'.evidence'=>$liveEvidence]);
$activationClass=Paymenter\Extensions\Servers\Domains\Services\EnomLiveActivation::class;
$assertActivate=function(string $state)use($bindings,$enom,$liveBinding,$activationClass,$checklist,$admin):void {
    same('matched',$bindings->configurationStatus((int)$enom->server_id),$state.' activation keeps verified credentials and the saved site');
    expect(!$activationClass::current($liveBinding),$state.' activation is not current');
    $orders=$checklist()['Orders'];
    same(['Off','processing'],[$orders['value'],$orders['action']],$state.' activation leaves Orders paused with its existing switch');
    expect(str_starts_with($orders['cause'],'Orders are paused. Use Activate to check setup and turn on Orders.'),$state.' activation sends Live Orders to Activate');
    Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('Use Activate to check setup and turn on Orders.');
};
$assertActivate('Missing');gc_collect_cycles();gc_mem_caches();
$activation=App\Models\Server::findOrFail($enom->server_id)->settings()->firstOrNew(['key'=>$activationClass::KEY]);
$activation->encrypted=true;$activation->type='string';$activation->value=json_encode(['fingerprint'=>$liveBinding->fingerprint,'site'=>$site,
    'evidence'=>hash('sha256',json_encode($liveEvidence,JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR);$activation->save();
expect($activationClass::current($liveBinding),'The saved activation matches current evidence');
expect(str_starts_with($checklist()['Orders']['cause'],'Orders are paused. Check setup, then turn on Orders.'),'Current activation keeps the normal Orders next step');
config(['domains.enom_live.accounts.'.$enom->stable_identity.'.evidence.sha256'=>str_repeat('b',64)]);
$assertActivate('Stale');gc_collect_cycles();gc_mem_caches();$activation->delete();
same(['Off','Paused until setup is approved. Use Activate to see what is missing.',null],[$checklist()['Renewals']['value'],explode(' 1 managed domain',$checklist()['Renewals']['cause'])[0],$checklist()['Renewals']['action']],'Live turn-on is not a switch');
$page=Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('Use Activate to see what is missing.')->assertActionDoesNotExist(setupAction('live_renewal',(int)$enom->server_id));
Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions::setup((int)$enom->server_id,$page->instance()->reviewStamp,'live_renewal')->getActionFunction()([],$page->instance());
same(0,App\Models\Server::findOrFail($enom->server_id)->settings()->where('key','domains_enom_live_renewal')->count(),'A Live switch with empty form data creates no acceptance');
unset($page);gc_collect_cycles();gc_mem_caches();
DB::table('domains_accounts')->where('id',$enom->id)->update(['environment'=>'test','live_site'=>null,'account_fingerprint'=>(new AccountBinding('enom',(int)$enom->server_id,Environment::Test,$enom->stable_identity))->fingerprint,
    'identity_fingerprint'=>hash('sha256',json_encode(['enom','test',$enom->stable_identity],JSON_THROW_ON_ERROR))]);
DB::table('domains_reminder_schedules')->where('server_id',$enom->server_id)->update(['account_fingerprint'=>(new AccountBinding('enom',(int)$enom->server_id,Environment::Test,$enom->stable_identity))->fingerprint]);
$bindings->configure((int)$enom->server_id,'enom',Environment::Test,['token'=>$enom->stable_identity]);
config(['domains.enom_identity.environment'=>'test','domains.enom_writes.renewal'=>true,'domains.enom_catalogue.enabled'=>false]);$noKeys();
same('Not available yet',$checklist()['Transfers (Experimental)']['value'],'TEST site off shows no switch');
config(['domains.enom_catalogue.enabled'=>true,'domains.enom_writes.transfer'=>true]);$noKeys();
same(['Off','test_transfer'],[$checklist()['Transfers (Experimental)']['value'],$checklist()['Transfers (Experimental)']['action']],'TEST transfers get a real switch once the site allows them');
// Ready Off shapes explain the pause and retain the existing turn-on actions.
config(['domains.enom_writes.management'=>true]);
$offRows=$checklist();
foreach(['Renewals'=>'test_renewal','DNS, nameservers and contacts'=>'test_management','Orders'=>'processing'] as $label=>$action){
    same(['Off',$action],[$offRows[$label]['value'],$offRows[$label]['action']],$label.' keeps its switch');
    expect(str_contains(strtolower($offRows[$label]['cause']??''),'paused')&&str_contains(strtolower($offRows[$label]['cause']??''),'turn on'),$label.' explains the pause and next step');
    same('warning',$offRows[$label]['tone'],$label.' warns about managed domains');
}
same('Not available yet',$siloRows()['Renewals']['value'],'Legacy NameSilo cannot enable renewal billing without sending');
$siloLive=App\Models\Server::create(['name'=>'Synthetic Live NameSilo','extension'=>'Domains','type'=>'server','enabled'=>true]);
$siloLiveReview=new StaffSettingsReview($admin->id,(int)$siloLive->id,null,'configure',
    StaffSettingsReview::ticket($admin->id,(int)$siloLive->id,null,'configure',StaffSettingsReview::stamp((int)$siloLive->id,null)),
    'Synthetic Live NameSilo account setup');
$bindings->configure((int)$siloLive->id,'namesilo',Environment::Live,['token'=>'synthetic-live-namesilo-orders'],$siloLiveReview);
same('site_unbound',$bindings->configurationStatus((int)$siloLive->id),'Live NameSilo starts without a confirmed site');
Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$siloLive->id])
    ->assertSee('Orders are paused. Ask your site admin to confirm the Live site for this account.')
    ->assertActionDoesNotExist('activate_live');
DB::table('domains_accounts')->where('server_id',$siloLive->id)->update(['live_site'=>$site]);
same('matched',$bindings->configurationStatus((int)$siloLive->id),'Live NameSilo credentials and saved site match');
$siloLiveRows=array_column(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup(Account::where('server_id',$siloLive->id)->sole(),['label'=>'Active','reason'=>null]),null,'label');
same('Orders are paused. Use Activate to check setup and turn on Orders.',$siloLiveRows['Orders']['cause'],'Live NameSilo uses the shared Activate review');
foreach(['invoices','customer credit','Auto Pay','paid renewals'] as $effect){expect(str_contains($offRows['Renewals']['help'],$effect),'Renewals explains '.$effect);}
$testBinding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::findOrFail($enom->id));
$review=new StaffSettingsReview($admin->id,(int)$enom->server_id,null,'test_renewal',StaffSettingsReview::ticket($admin->id,(int)$enom->server_id,null,'test_renewal',StaffSettingsReview::stamp((int)$enom->server_id,null)),'Approve test renewal setup');
Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::review($review,['accept'=>true,'verified'=>true]);
$late=$checklist()['Late renewal'];
same(['Off','late_renewal'],[$late['value'],$late['action']],'Ready Test late renewal keeps its separate approval');
expect(str_contains($late['cause'],'paused')&&str_contains($late['cause'],'expired domains')&&!str_contains($late['cause'],'evidence'),'Ready Test late renewal explains billing without asking for Live evidence');
same(null,$late['tone'],'A future-expiry managed domain is not a Late renewal warning');
expect(!str_contains($late['cause'],'managed domain'),'Late renewal does not count a healthy future-expiry domain');
$domain=Domain::where('server_id',$enom->server_id)->sole();$savedExpiry=$domain->getRawOriginal('provider_expires_at');
DB::table('domains_domains')->where('id',$domain->id)->update(['provider_expires_at'=>$clock->now()->modify('-1 day')->format('Y-m-d')]);
$expiredLate=$checklist()['Late renewal'];
same('warning',$expiredLate['tone'],'An expired managed domain needs Late renewal attention');
expect(str_contains($expiredLate['cause'],'1 managed domain needs attention'),'Late renewal counts the expired managed domain');
DB::table('domains_domains')->where('id',$domain->id)->update(['provider_expires_at'=>$clock->now()->format('Y-m-d')]);
foreach(['2026-10-18T12:00:00Z','2026-10-18T00:30:00Z'] as $expiryDay){
    app()->instance(Clock::class,new Paymenter\Extensions\Servers\Domains\Tests\FakeClock(new DateTimeImmutable($expiryDay)));
    $todayLate=$checklist()['Late renewal'];
    same('warning',$todayLate['tone'],'Expiry today needs Late renewal attention at '.$expiryDay);
    expect(str_contains($todayLate['cause'],'1 managed domain needs attention'),'Late renewal counts expiry today at '.$expiryDay);
}
app()->instance(Clock::class,$clock);
DB::table('domains_domains')->where('id',$domain->id)->update(['provider_expires_at'=>$savedExpiry]);
require __DIR__.'/Support/reminder-upgrade.php';

// Completed render cases retain cyclic Filament schemas; release them before the action cases.
unset($page,$offRows);gc_collect_cycles();gc_mem_caches();
// Real Livewire actions prove permissions, reviewed settings persistence and audit together.

// Account registration defaults are local, audited and shared across providers.
// The full fixture installation already owns the DNS-template schemas.
// Global pricing saves reload Paymenter settings; keep this branding fixture in the same persisted source.
App\Models\Setting::updateOrCreate(['key'=>'company_name','settingable_type'=>null,'settingable_id'=>null],['type'=>'string','encrypted'=>false,'value'=>'Example Hosting']);
config(['settings.company_name'=>'Example Hosting','app.name'=>'Generic app']);
$template=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Models\WatchProfile::create(['name'=>'Shared registration DNS','enabled'=>true,
    'nameservers'=>['ns1.provider.example','ns2.provider.example'],'website_endpoints'=>[],'email_endpoints'=>[],
    'edited_by'=>$admin->id,'reason'=>'Synthetic registration template']));
$action=TestAction::make('new_domain_dns_'.$enom->server_id)->schemaComponent('account_'.$enom->server_id,'content');
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertActionDoesNotExist($action);
$page=Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('New domains use')->assertSee('Choose an enabled DNS template with Change. Create one under DNS templates first.')->assertSee('Example Hosting does not sell it yet.');
$anchorDom=new DOMDocument();@$anchorDom->loadHTML($page->html());
$target=(new DOMXPath($anchorDom))->query('//*[@id="switch-template"]');
$dnsTargetReady=$target->length===1&&str_contains($target->item(0)->textContent,'New domains use');
$page->assertActionExists($action,fn($action)=>$action->getLabel()==='Change'&&$action->getModalHeading()==='New domains use');
$page->callAction($action,['dns_template'=>(string)$template->id])->assertHasNoActionErrors()->assertNotified('Saved');
same($template->id,\Paymenter\Extensions\Servers\Domains\Services\NewDomainDns::profile($enom->server_id)->id,'Account form saves the default');
Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('Shared registration DNS')->assertSee('ns1.provider.example');
$history=StaffSettingsReview::historyRows(App\Models\Server::find($enom->server_id));$last=end($history);
same('Change new domain nameservers',$last['action'],'DNS template selection is audited');same($template->id,$last['after']['dns_template'],'Audit includes the selected template');
Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$silo->server_id])->callAction(TestAction::make('new_domain_dns_'.$silo->server_id)->schemaComponent('account_'.$silo->server_id,'content'),['dns_template'=>(string)$template->id])->assertHasNoActionErrors()->assertNotified('Saved');
same(2,count(\Paymenter\Extensions\Servers\Domains\Services\NewDomainDns::accounts($template->id)),'One DNS template can serve both registrar accounts');
// Retained settings can outlive a template or an old enabled state; neither is an explicit None.
$dnsSetting=App\Models\Server::find($enom->server_id)->settings()->where('key',\Paymenter\Extensions\Servers\Domains\Services\NewDomainDns::KEY)->sole();
DB::table('domains_watch_profiles')->where('id',$template->id)->update(['enabled'=>false]);
Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('Choose an enabled DNS template with Change.');
DB::table('domains_watch_profiles')->where('id',$template->id)->update(['enabled'=>true]);
$dnsSetting->update(['value'=>(string)($template->id+1000)]);
Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSee('Choose an enabled DNS template with Change.');
$dnsSetting->update(['value'=>(string)$template->id]);
Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->callAction($action,['dns_template'=>null])->assertHasNoActionErrors()->assertNotified('Saved');
same(null,\Paymenter\Extensions\Servers\Domains\Services\NewDomainDns::profile($enom->server_id),'Registrar defaults do not invent a monitoring template');
$defaultsPage=Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id]);
$defaultsSet=str_contains($defaultsPage->html(),'Registrar defaults')&&!str_contains($defaultsPage->html(),'Choose an enabled DNS template with Change.');
same([true,true],[$dnsTargetReady,$defaultsSet],'DNS setup row has a real New domains use target and treats explicit registrar defaults as set: '.json_encode([$dnsTargetReady,$defaultsSet]));

echo "Registrars list and account page passed\n";$nativeSuccessful=true;
