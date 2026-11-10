<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// A DNS template's Monitored domains counts only domains checked against it, and says when they follow the site default.
require __DIR__.'/Support/watch-dns.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\ViewConfiguration as ViewTemplate;
use Paymenter\Extensions\Servers\Domains\Admin\Support\WatchFacts;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault;
config(['domains.site_dns_default.nameservers'=>['ns1.deployment.test','ns2.deployment.test']]);
extract(dnsFixture());
staffPanel();$panel=\Filament\Facades\Filament::getCurrentPanel();
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource::registerRoutes($panel));app('router')->getRoutes()->refreshNameLookups();
$manager=staffUser(['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.new_domain_dns','admin.domains.watch.view','admin.domains.watch.manage']);
Auth::setUser($manager);
$default=SiteDnsDefault::profile();
$other=DB::transaction(fn()=>WatchProfile::create(['name'=>'Second template','nameservers'=>['ns1.second.test','ns2.second.test'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic second']));
DB::table('domains_watch_profiles')->where('id',$other->id)->update(['enabled'=>true]);
$note=fn(int $id)=>Livewire::actingAs($manager)->test(ViewTemplate::class,['record'=>$id])->html();
// The monitored name has no template of its own, so it follows the site default.
DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>null,'profile_revision'=>0,'expected_dns'=>null]);
same(1,WatchFacts::usedBy($default->id),'The default template counts the domain that follows it');
same(0,WatchFacts::usedBy($other->id),'Another template does not');
expect(str_contains($note($default->id),'1 domain has no template of its own and follows the site DNS default. It moves to the new default when it changes.'),'The default template says its count follows the default');
SiteDnsDefault::save($manager,$other->id);
same([0,1],[WatchFacts::usedBy($default->id),WatchFacts::usedBy($other->id)],'Changing the default moves the inherited domain to the new default, not to nothing');
expect(!str_contains($note($default->id),'follows the site DNS default'),'The old default no longer claims it');
// A monitored name that chose a template that is now off is not checked, so it is not counted as monitored.
DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>$profile->id,'profile_revision'=>1,'expected_dns'=>'platform']);
DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['enabled'=>false]);
same(['checked'=>0,'inherited'=>0,'unchecked'=>1],WatchFacts::usage($profile->id),'An off template checks nothing and names the domain that still uses it');
same(0,WatchFacts::usedBy($profile->id),'An off template counts no monitored domains');
expect(str_contains($note($profile->id),'1 domain uses this template and is not checked while it is off. Turn it on or choose another template for it.'),'The off template says why its domain is not counted');
$nativeSuccessful=true;echo "Staff template usage passed\n";
