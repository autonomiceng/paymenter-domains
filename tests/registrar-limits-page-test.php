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
// ui-r17: with an account, the API connection row says why, so the Overview head names only the status.
App\Models\Server::whereKey($silo->server_id)->update(['enabled'=>false]);
same(1,substr_count(Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$silo->server_id])->assertSee('Not connected')->html(),'Off in Paymenter'),'The cause shows once on the account page');
App\Models\Server::whereKey($silo->server_id)->update(['enabled'=>true]);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$empty->id])->assertSee('Not connected')->assertDontSee('Check balance');
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$demo->server_id])->assertSee('Demo NameSilo')->assertSee('Demo');
// Orders paused: by staff, and by an unconfirmed paid request.
DB::table('domains_accounts')->where('server_id',$silo->server_id)->update(['processing_enabled'=>false]);
$pausedHtml=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$silo->server_id])->assertSee('Orders paused')->assertSee('Paused by staff')->assertSee('Check setup, then turn on Orders.')->html();
// ui-r19: the head says Orders paused, so the Orders row (Off) gives only the next step.
same(1,substr_count($pausedHtml,'Orders paused')+substr_count($pausedHtml,'Orders are paused'),'Orders paused shows once on the account page');unset($pausedHtml);
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
echo "Registrar limits, account modes and request actions passed.\n";$nativeSuccessful=true;
