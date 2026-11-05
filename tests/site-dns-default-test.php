<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
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
$entry->upgraded();same($default->id,SiteDnsDefault::profile()->id,'Upgrade keeps one stable default reference');
same(1,WatchProfile::where('name','Beco DNS')->count(),'Upgrade does not duplicate the deployment template');
// Null watch choices inherit the site reference without storing a per-watch assignment.
DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>null,'profile_revision'=>0]);
$checks=new WatchDnsChecks(true);$at=1800000000;
expect($checks->enroll($watch->id,$owner->id,$at),'An unassigned hosting watch enrolls with the site default');
$ticket=$checks->begin($watch->id,$owner->id,'delegation',$at);
same($default->id,$ticket?->profileId,'Public DNS worker uses inherited site default');
same(null,$watch->fresh()->ns_profile_id,'Inheritance never stores an automatic profile choice');
$manager=staffUser(['admin.domains.view','admin.domains.watch.view','admin.domains.watch.manage']);
$viewer=staffUser(['admin.domains.view','admin.domains.watch.view']);
$fake=new \Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader;
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\WatchWork::class,new \Paymenter\Extensions\Servers\Domains\Services\WatchWork($fake,new \Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryWork(fn()=>0),true,fn()=>$at));
$replacement=DB::transaction(fn()=>WatchProfile::create(['name'=>'Explicit replacement','enabled'=>true,'nameservers'=>['ns1.other.test','ns2.other.test'],
    'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic site change']));
SiteDnsDefault::save($manager,$replacement->id);
expect(!$checks->finish($ticket,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','platform'),$at+1),'Site change rejects an in-flight old comparison');
same($replacement->id,$checks->begin($watch->id,$owner->id,'delegation',$at)?->profileId,'Site change queues a current comparison');
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
$nativeSuccessful=true;echo "Site DNS installation default passed.\n";
