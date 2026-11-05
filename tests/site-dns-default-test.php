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
same(1,WatchProfile::where('name','Beco DNS')->where('enabled',true)->count(),'Installation seeds one enabled deployment DNS template');
$default=SiteDnsDefault::profile();
same(['ns1.deployment.test','ns2.deployment.test'],$default->nameservers,'Deployment owns the nameservers');
same($default->id,NewDomainDns::profile($server->id)->id,'Site default works independently of registrar account');
same([],NewDomainDns::capture($server->id,'transfer'),'Site DNS default never supplies transfer nameservers');
same('site_default',NewDomainDns::capture($server->id,'register')['nameservers_origin']['source'],'Registration freezes site-default provenance');
$entry->upgraded();same($default->id,SiteDnsDefault::profile()->id,'Upgrade keeps one stable default reference');
same(1,WatchProfile::where('name','Beco DNS')->count(),'Upgrade does not duplicate the deployment template');
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
\Livewire\Livewire::actingAs($manager)->test($page)->assertSee(SiteDnsDefault::MISSING)->callAction('siteDefault',['profile'=>$default->id])->assertHasNoActionErrors();
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
$refuse(fn()=>SiteDnsDefault::save($manager,$one->id),'NameSilo requires 2 to 13');
SiteDnsDefault::save($manager,$thirteen->id);same(13,count(SiteDnsDefault::profile()->nameservers),'NameSilo accepts thirteen');
$refuse(fn()=>DB::transaction(fn()=>$thirteen->fresh()->update(['nameservers'=>$servers(1),'revision'=>$thirteen->revision+1])),'NameSilo requires 2 to 13');
SiteDnsDefault::save($manager,$default->id);$connect('enom');
$refuse(fn()=>SiteDnsDefault::save($manager,$one->id),'Enom requires 2 to 12');
$refuse(fn()=>SiteDnsDefault::save($manager,$thirteen->id),'Enom requires 2 to 12');
foreach([1,13]as$count){$refuse(fn()=>DB::transaction(fn()=>$default->fresh()->update(['nameservers'=>$servers($count),'revision'=>$default->revision+1])),'Enom requires 2 to 12');}
$twelve=$make(12);SiteDnsDefault::save($manager,$twelve->id);same(12,count(SiteDnsDefault::profile()->nameservers),'Both connected registrars accept twelve');
DB::transaction(fn()=>$twelve->fresh()->update(['nameservers'=>$servers(2),'revision'=>$twelve->revision+1]));
same(2,count(SiteDnsDefault::profile()->nameservers),'Both connected registrars accept editing to two');
$nativeSuccessful=true;echo "Site DNS installation default passed.\n";
