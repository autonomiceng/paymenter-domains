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
// Account page.
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSuccessful();
foreach(['Enom test account','Active','Check balance','Account','Registrar','Enom','Mode','Test','Balance','USD 7.99','Low balance','Add funds','Due in 30 days','USD 8.00',
    'Domains','API calls, last 24 hours','0 of 1,000'] as $needle){$page->assertSee($needle);}
$html=$page->html();
foreach(['https://cp.enom.com/','Registrars'] as $needle){expect(str_contains($html,$needle),'Account page: '.$needle);}
foreach(['Server #','Unknown','Enom TEST daily calls','Verified user ID','Retained domains','Environment',"\u{2014}"] as $needle){expect(!str_contains($html,$needle),'Account page never shows: '.$needle);}
// System checks, renewal section and header actions for staff who can change settings.
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
echo "Registrar account, reminders, history and system checks passed.\n";$nativeSuccessful=true;
