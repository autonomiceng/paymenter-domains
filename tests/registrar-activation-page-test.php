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
$bindings->setProcessing((int)$enom->server_id,false);
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
    expect($row['label']==='Transfers (Experimental)'||!preg_match('/domains\.|_[a-z]+_|site config/',($row['cause']??'').' '.($row['help']??'')),'No setting name in '.$row['label'].': '.($row['cause']??'').' '.($row['help']??''));expect($row['label']==='Reminder schedule'||($row['help']??'')!==''||($row['cause']??'')!=='','Every row explains itself: '.$row['label']);}};
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
Livewire::actingAs($admin)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertActionDoesNotExist($action)->assertDontSee('New domains use');
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertActionDoesNotExist($action);

echo "Registrar identity repair, activation and renewal settings passed.\n";$nativeSuccessful=true;
