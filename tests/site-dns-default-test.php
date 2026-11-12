<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
require __DIR__.'/Support/FakeDnsReader.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,NewDomainDns,WatchDnsChecks};
// Installation has no staff actor yet. Deployment supplies literal public names, never registrar credentials.
config(['domains.site_dns_default.nameservers'=>['ns1.deployment.test','ns2.deployment.test']]);
extract(dnsFixture());
same(1,WatchProfile::where('name','Site DNS')->where('enabled',true)->count(),'Installation seeds one enabled deployment DNS template');
$default=SiteDnsDefault::profile();
same(['ns1.deployment.test','ns2.deployment.test'],$default->nameservers,'Deployment owns the nameservers');
same($default->id,NewDomainDns::profile($server->id)->id,'Site default works independently of registrar account');
same([],NewDomainDns::capture($server->id,'transfer'),'Site DNS default never supplies transfer nameservers');
same('site_default',NewDomainDns::capture($server->id,'register')['nameservers_origin']['source'],'Registration freezes site-default provenance');
$legacy='domains_new_domain_dns_template';$legacyServers=[];
foreach(['',(string)$profile->id]as$value){$accountServer=\App\Models\Server::create(['name'=>'Retired account DNS','extension'=>'Domains','type'=>'server','enabled'=>true]);$legacyServers[]=$accountServer;
 $accountServer->settings()->create(['key'=>$legacy,'type'=>'string','encrypted'=>false,'value'=>$value]);}
$reading=false;$legacyRead=false;DB::listen(static function($query)use(&$reading,&$legacyRead,$legacy):void{if($reading&&str_starts_with($query->sql,'select')&&in_array($legacy,$query->bindings,true)){$legacyRead=true;}});
$reading=true;foreach($legacyServers as$accountServer){same($default->id,NewDomainDns::profile($accountServer->id)->id,'Neither retired None nor a retired account template affects site DNS');}$reading=false;
expect(!$legacyRead,'Registration never reads the retired account setting');
$entry->upgraded();same($default->id,SiteDnsDefault::profile()->id,'Upgrade keeps one stable default reference');
same(0,\App\Models\Setting::where('key',$legacy)->count(),'Site-default upgrade deletes retired account DNS settings');
same(1,WatchProfile::where('name','Site DNS')->count(),'Upgrade does not duplicate the deployment template');
// Null watch choices inherit the site reference without storing a per-watch assignment.
DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>null,'profile_revision'=>0,'expected_dns'=>null]);
$checks=new WatchDnsChecks(true);$at=1800000000;
expect($checks->enroll($watch->id,$owner->id,$at),'An unassigned hosting watch enrolls with the site default');
$ticket=$checks->begin($watch->id,$owner->id,'delegation',$at);
same($default->id,$ticket?->profileId,'Public DNS worker uses inherited site default');
same(null,$watch->fresh()->ns_profile_id,'Inheritance never stores an automatic profile choice');
expect($checks->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','platform'),$at+1),'Inherited watch observation commits');
$notice=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\WatchNoticeTarget::capture('watch',$watch->id,$owner->id,$at+1));
same('platform',$notice['delegation']['dns']??null,'Inherited watch notice target uses the same stamp as its DNS reader');
DB::table('domains_watch_work')->where('watch_id',$watch->id)->where('kind','delegation')->update(['due_at'=>$at+2]);
$ticket=$checks->begin($watch->id,$owner->id,'delegation',$at+2);

$manager=staffUser(['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.new_domain_dns','admin.domains.watch.view','admin.domains.watch.manage']);
\Illuminate\Support\Facades\Auth::setUser($manager);
same(1,\Paymenter\Extensions\Servers\Domains\Admin\Support\WatchFacts::usedBy($default->id),'Template usage includes an inherited active watch');
$viewer=staffUser(['admin.domains.view','admin.domains.watch.view','admin.domains.watch.manage']);
$fake=new \Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader;
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\WatchWork::class,new \Paymenter\Extensions\Servers\Domains\Services\WatchWork($fake,new \Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryWork(fn()=>0),true,fn()=>$at+2));
$replacement=DB::transaction(fn()=>WatchProfile::create(['name'=>'Explicit replacement','enabled'=>true,'nameservers'=>['ns1.other.test','ns2.other.test'],
    'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic site change']));
SiteDnsDefault::save($manager,$replacement->id);
expect(!$checks->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','platform'),$at+3),'Site change rejects an in-flight old comparison');
same($replacement->id,$checks->begin($watch->id,$owner->id,'delegation',$at+2)?->profileId,'Site change queues a current comparison');
foreach(['paused','retired']as$state){
    DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>$state]);
    $before=$watch->fresh()->getRawOriginal();$work=DB::table('domains_watch_work')->where('watch_id',$watch->id)->get()->toJson();
    SiteDnsDefault::save($manager,$default->id);
    same($before,$watch->fresh()->getRawOriginal(),'Site changes preserve '.$state.' watch');
    same($work,DB::table('domains_watch_work')->where('watch_id',$watch->id)->get()->toJson(),'Site changes leave '.$state.' work untouched');
}
DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'active','ns_profile_id'=>$replacement->id,'expected_dns'=>'external']);
SiteDnsDefault::save($manager,$default->id);
same($replacement->id,SiteDnsDefault::watchProfile($watch->fresh())->id,'Explicit watch template survives site changes');
same([], $fake->calls,'Reconciliation performs no DNS or registrar I/O');
// Mount the actual settings page to verify Missing and the one site-level writer with permission checks.
staffPanel();
$panel=\Filament\Facades\Filament::getCurrentPanel();$panel->resources([\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource::class]);
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource::registerRoutes($panel));
app('router')->getRoutes()->refreshNameLookups();
$page=\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\MonitoringSettings::class;
\Livewire\Livewire::actingAs($viewer)->test($page)->assertActionHidden('siteDefault');
\App\Models\Setting::where('key',SiteDnsDefault::KEY)->whereNull('settingable_type')->delete();
config(['domains.site_dns_default.nameservers'=>null]);$entry->upgraded();
$settings=\Livewire\Livewire::actingAs($manager)->test($page)->assertSee(SiteDnsDefault::MISSING);
// ui-r15 R4: the step is said once, in the red callout; the Site DNS default row shows only Missing.
same(1,substr_count($settings->html(),'Choose a set with Change site DNS default'),'Site DNS default step shows once');
$settings->callAction('siteDefault',['profile'=>$default->id])->assertHasNoActionErrors();
same($default->id,SiteDnsDefault::profile()->id,'Site settings save a single global reference');
app(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class)->boot();
DB::table('domains_watch_work')->where('watch_id',$watch->id)->delete();
DB::transaction(fn()=>$source->fresh()->fill(['revision'=>$source->revision+1,'last_seen_at'=>$at+1])->save());
same(2,DB::table('domains_watch_work')->where('watch_id',$watch->id)->whereIn('kind',['delegation','points'])->count(),'Source attachment events enroll DNS work before the daily sweep');
// Connected registrar rules also apply while processing is paused. No provider verification or send is faked here:
// privileged immutable bindings supply only the native relationship used by these local policy writes.
$connect=static function(string $key):void {
    $s=\App\Models\Server::create(['name'=>'Synthetic '.$key,'extension'=>'Domains','type'=>'server','enabled'=>true]);
    \Paymenter\Extensions\Servers\Domains\Models\Account::withVerifiedBindingWrite(fn()=>\Paymenter\Extensions\Servers\Domains\Models\Account::create([
        'server_id'=>$s->id,'registrar_key'=>$key,'environment'=>'test','stable_identity'=>'synthetic-'.$key,'processing_enabled'=>false]));
};
$servers=static fn(int $count)=>array_map(static fn($n)=>'ns'.$n.'.registrar.test',range(1,$count));
$make=static fn(int $count)=>DB::transaction(fn()=>WatchProfile::create(['name'=>'Count '.$count,'enabled'=>true,'nameservers'=>$servers($count),
    'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic registrar limit']));
$refuse=static function(Closure $write,string $rule):void {
    $before=[SiteDnsDefault::profile()->id,WatchProfile::orderBy('id')->get()->toJson(),\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count()];
    $error=null;try{$write();}catch(DomainException $e){$error=$e->getMessage();}
    expect($error!==null && str_contains($error,$rule),'Save refuses with the registrar rule: '.$rule);
    same($before,[SiteDnsDefault::profile()->id,WatchProfile::orderBy('id')->get()->toJson(),\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count()],'Refused save preserves selection, templates and audit');
};
$connect('namesilo');$one=$make(1);$thirteen=$make(13);
// The native page must present the local registrar refusal, rather than leaking an action exception.
$pageBefore=[SiteDnsDefault::profile()->id,WatchProfile::orderBy('id')->get()->toJson(),\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count()];
\Livewire\Livewire::actingAs($manager)->test($page)->callAction('siteDefault',['profile'=>$one->id])
    ->assertSuccessful()->assertActionHalted('siteDefault')
    ->assertNotified(\Filament\Notifications\Notification::make()->warning()->title('NameSilo takes 2 to 13 nameservers. Update this DNS template or choose another one.'))
    ->assertNotNotified('Site DNS default saved');
same($pageBefore,[SiteDnsDefault::profile()->id,WatchProfile::orderBy('id')->get()->toJson(),\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count()],'Page refusal preserves selection, templates and audit');
$refuse(fn()=>SiteDnsDefault::save($manager,$one->id),'NameSilo takes 2 to 13');
SiteDnsDefault::save($manager,$thirteen->id);same(13,count(SiteDnsDefault::profile()->nameservers),'NameSilo accepts thirteen');
$refuse(fn()=>DB::transaction(fn()=>$thirteen->fresh()->update(['nameservers'=>$servers(1),'revision'=>$thirteen->revision+1])),'NameSilo takes 2 to 13');
SiteDnsDefault::save($manager,$default->id);$connect('enom');
$refuse(fn()=>SiteDnsDefault::save($manager,$one->id),'Enom takes 2 to 12');
$refuse(fn()=>SiteDnsDefault::save($manager,$thirteen->id),'Enom takes 2 to 12');
foreach([1,13]as$count){$refuse(fn()=>DB::transaction(fn()=>$default->fresh()->update(['nameservers'=>$servers($count),'revision'=>$default->revision+1,'edited_by'=>$manager->id,'reason'=>'Synthetic staff limit edit'])),'Enom takes 2 to 12');}
$twelve=$make(12);SiteDnsDefault::save($manager,$twelve->id);same(12,count(SiteDnsDefault::profile()->nameservers),'Both connected registrars accept twelve');
DB::transaction(fn()=>$twelve->fresh()->update(['nameservers'=>$servers(2),'revision'=>$twelve->revision+1]));
same(2,count(SiteDnsDefault::profile()->nameservers),'Both connected registrars accept editing to two');
// Hostname refusals are not server-count failures; no saved default means create/select recovery.
try{SiteDnsDefault::validateRegistrars(['invalid host','ns2.example.test']);expect(false,'Invalid hostname refused');}
catch(DomainException $e){expect(str_contains(strtolower($e->getMessage()),'hostname')&&!str_contains($e->getMessage(),'takes 2 to'),'Hostname refusal explains the relevant rule');}
\App\Models\Setting::where('key',SiteDnsDefault::KEY)->delete();DB::table('domains_watch_profiles')->update(['enabled'=>false]);
config(['domains.site_dns_default.nameservers'=>null]);
\Livewire\Livewire::actingAs($viewer)->test($page)->assertSee('Ask staff who can change the site DNS default.')->assertDontSee('Choose a set with Change site DNS default');
$empty=\Livewire\Livewire::actingAs($manager)->test($page)->mountAction('siteDefault')->call('$refresh');
expect(str_contains(html_entity_decode(implode('', $empty->effects['partials']??[]).$empty->html(),ENT_QUOTES),'Create a DNS template in Domains > DNS templates, turn it on, then choose it here.'),'Empty selector explains create, enable and select');
config(['domains.site_dns_default.nameservers'=>['invalid host','ns2.example.test']]);
expect(str_contains((string)SiteDnsDefault::refusal(),'Create and turn on a compatible DNS template'),'An unusable deployment seed does not tell staff to edit a nonexistent saved template');
// Too many valid deployment hosts is a count problem, not a hostname problem; registrar limits still apply below it.
$hosts=static fn(int $count)=>array_map(static fn($n)=>'ns'.$n.'.deployment.test',range(1,$count));
config(['domains.site_dns_default.nameservers'=>$hosts(14)]);$limit=(string)SiteDnsDefault::refusal();
expect(str_contains($limit,'more than 13 nameservers')&&!str_contains(strtolower($limit),'hostname'),'Fourteen valid deployment hosts report the 13-host limit: '.$limit);
expect(str_contains($limit,'Create and turn on a compatible DNS template, then choose it as the site DNS default.'),'The limit keeps template-selection recovery');
config(['domains.site_dns_default.nameservers'=>[...$hosts(13),'bad host']]);
expect(str_contains((string)SiteDnsDefault::refusal(),'hostnames are invalid'),'A malformed host among many is still a hostname problem');
config(['domains.site_dns_default.nameservers'=>$hosts(13)]);
expect(str_starts_with((string)SiteDnsDefault::refusal(),'Enom takes 2 to 12 nameservers.'),'Thirteen valid hosts keep the connected Enom limit');
config(['domains.site_dns_default.nameservers'=>$hosts(12)]);same(null,SiteDnsDefault::refusal(),'Twelve valid hosts suit both connected registrars');
// Read-only staff keep the cause and are sent to staff who can change the default.
config(['domains.site_dns_default.nameservers'=>$hosts(14)]);
\Livewire\Livewire::actingAs($viewer)->test($page)->assertSee('Deployment DNS default lists more than 13 nameservers.')->assertSee('Ask staff who can change the site DNS default.')->assertDontSee('Create and turn on a compatible DNS template');
\Livewire\Livewire::actingAs($manager)->test($page)->assertSee('Create and turn on a compatible DNS template')->assertDontSee('Ask staff who can change the site DNS default.');
DB::table('domains_watch_profiles')->where('id',$thirteen->id)->update(['enabled'=>true]);
\App\Models\Setting::updateOrCreate(['key'=>SiteDnsDefault::KEY,'settingable_type'=>null,'settingable_id'=>null],['type'=>'string','encrypted'=>false,'value'=>(string)$thirteen->id]);
same('Enom takes 2 to 12 nameservers. Update this DNS template or choose another one.',SiteDnsDefault::refusal(),'Editors are told to update or replace a saved default');
\Livewire\Livewire::actingAs($viewer)->test($page)->assertSee('Enom takes 2 to 12 nameservers. Ask staff who can change the site DNS default.')->assertDontSee('Update this DNS template or choose another one.');
$nativeSuccessful=true;echo "Site DNS installation default passed.\n";
