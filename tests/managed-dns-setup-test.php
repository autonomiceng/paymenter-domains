<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native ownership, retained enrollment and Filament actions must share the real database transaction path.
require __DIR__.'/Support/managed-public.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{ManagedPublicConfig,ManagedPublicObservation};
use Paymenter\Extensions\Servers\Domains\Services\{ManagedPublicSignals,ManagedPublicReconciliation,ManagedPublicWorkSchema};
extract(managedFixture(false,false));$id=(int)$d->id;$uid=(int)$owner->id;$now=$time->now=time();
$signals=new ManagedPublicSignals($fake,true,fn()=>$time->now,$profile->id);
expect($signals->enqueue($id,$uid),'Eligible domain retains monitoring configuration');
same(null,ManagedPublicConfig::find($id)->profile_id,'Global default cannot establish intended DNS');
same([], (new ManagedPublicReconciliation($signals))->due($now),'Unconfigured domain has no runnable DNS work');
expect(!$signals->observe($id,$uid,'delegation'),'Unconfigured domain cannot run a check');
same(0,ManagedPublicObservation::count(),'Setup absence is not a failed observation');
same([], $fake->calls,'No resolver request without expectations');

use Paymenter\Extensions\Servers\Domains\Models\{WatchAudit,WatchName,WatchSource,WatchProductMap};
use Paymenter\Extensions\Servers\Domains\Services\{ManagedDnsSetup,StaffManagedDnsSetup,StaffWatchWrites,WatchScheduleSchema,StaffWatchSchema,WatchMaintenance,WatchWork,WatchDiscoveryWork};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListManagedDns,ListDnsFailures,ListWatches};
use Illuminate\Support\Facades\{Auth,Route};
use Filament\Facades\Filament;
use Livewire\Livewire;
app('view')->addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
WatchScheduleSchema::install();\Paymenter\Extensions\Servers\Domains\Services\WatchNoticeSchema::install();StaffWatchSchema::install();staffPanel();
$panel=Filament::getCurrentPanel();$panel->resources([WatchResource::class,RoutingProfileResource::class,\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::class])->pages([\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class]);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel){WatchResource::registerRoutes($panel);RoutingProfileResource::registerRoutes($panel);\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::registerRoutes($panel);\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::registerRoutes($panel);});
require dirname(__DIR__).'/Domains/routes/owned.php';require dirname(__DIR__).'/Domains/routes/web.php';
app('router')->getRoutes()->refreshNameLookups();
$manager=staffUser([Staff::VIEW,StaffWatchWrites::VIEW,StaffWatchWrites::MANAGE]);$viewer=staffUser([Staff::VIEW,StaffWatchWrites::VIEW]);
DB::table('domains_watch_schedule')->insert(['scope'=>'dispatch','progress_at'=>$now]);
DB::table('domains_watch_schedule')->insert(['scope'=>'health_managed_dns','cursor'=>$now-90000]);
Auth::setUser($manager);
require __DIR__.'/Support/account-default-monitoring.php';
$m=new WatchMaintenance();expect(!$m->health($now)['outage'],'Unconfigured retained work never becomes a resolver outage');
same(1,ManagedDnsSetup::affected($now)->count(),'Eligible missing template counted');
$setupTabs=array_values(array_filter(\Paymenter\Extensions\Servers\Domains\Admin\Support\StaffTabs::monitoring('DNS monitoring setup'),fn($tab)=>$tab->getLabel()==='DNS monitoring setup'));
same(1,count($setupTabs),'Affected domains retain their DNS monitoring setup tab');
same(WatchResource::getUrl('dns-setup'),$setupTabs[0]->getUrl(),'Setup tab retains its destination');
expect($setupTabs[0]->isActive(),'Setup tab is active on the setup page');
DB::table('services')->where('id',$service->id)->update(['status'=>'cancelled']);same(0,ManagedDnsSetup::affected($now)->count(),'Cancelled service omitted from setup count');
expect(!in_array('DNS monitoring setup',array_map(fn($tab)=>$tab->getLabel(),\Paymenter\Extensions\Servers\Domains\Admin\Support\StaffTabs::monitoring('Monitored domains')),true),'No setup tab when no domains need setup');
DB::table('services')->where('id',$service->id)->update(['status'=>'active']);
$summary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->one($owner,'managed',$id,$now);
expect(in_array('domain.dns.not_configured',$summary->stateKeys,true),'Customer sees comparison setup state');same($d->provider_expires_at->format('Y-m-d'),$summary->expiry,'Expiry retained without DNS checks');
// Empty nameserver evidence must describe whether a complete, current check exists.
foreach([[null,true,true,'after the first check'],[$now-90000,true,false,'after the next check'],[$now,false,true,'after the next check'],[$now,false,false,'None observed']]as[$checkedAt,$stale,$partial,$copy]){
    $renderSummary=new \Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary(...array_replace(get_object_vars($summary),['freshness'=>array_replace($summary->freshness,['delegation'=>compact('checkedAt','stale','partial')])]));
    $html=view('domains::partials.summary',['summary'=>$renderSummary,'summaryOnManaged'=>true])->render();
    expect(preg_match('~<dt>Nameservers</dt><dd>(.*?)</dd>~s',$html,$nameserverRow)===1,'Nameserver row rendered');
    expect(str_contains($nameserverRow[1],$copy) && ($copy==='None observed' || !str_contains($nameserverRow[1],'None observed')),'Empty nameserver evidence: '.$copy.' / stale='.($stale?'yes':'no').' / partial='.($partial?'yes':'no'));
}
// Customer setup notices follow the staff list's eligibility, including pending adoption.
foreach([['active',null,true],['grace',null,true],['cancelled',null,false],['expired',null,false],['redemption',null,false],['registration_pending',null,false],['transfer_pending',null,false],['adoption_pending',null,false],['active','adoption_pending',false]]as[$status,$provenance,$eligible]){
    DB::table('domains_domains')->where('id',$id)->update(['status'=>$status,'provenance'=>$provenance]);
    $state=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->one($owner,'managed',$id,$now);
    same($eligible,ManagedDnsSetup::eligible($now)->whereKey($id)->exists(),'Setup list eligibility for '.$status.' / '.$provenance);
    same($eligible,in_array('domain.dns.not_configured',$state->stateKeys,true),'Customer setup notice follows eligibility for '.$status.' / '.$provenance);
}
DB::table('domains_domains')->where('id',$id)->update(['status'=>$d->status,'provenance'=>$d->provenance]);
Livewire::actingAs($owner)->test(ListManagedDns::class)->assertForbidden();
Livewire::actingAs($viewer)->test(ListManagedDns::class)->assertCanSeeTableRecords([$d])->assertTableActionHidden('assignTemplate',$d);
app()->instance(WatchWork::class,new WatchWork($fake,new WatchDiscoveryWork(fn()=>0),true,fn()=>$time->now));
$dnsPermission=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::permission('new_domain_dns');
foreach([[] , [Staff::POLICY], [$dnsPermission], [Staff::POLICY,$dnsPermission]]as$extra){
    $actor=staffUser([Staff::VIEW,StaffWatchWrites::VIEW,StaffWatchWrites::MANAGE,...$extra]);$canEdit=count($extra)===2;
    $page=Livewire::actingAs($actor)->test(ListManagedDns::class);
    $canEdit?$page->assertTableActionVisible('accountTemplate',$d):$page->assertTableActionHidden('accountTemplate',$d);
    $noticePage=Livewire::actingAs($actor)->test(ListWatches::class);
    $canEdit?$noticePage->assertSee('Set account DNS template'):$noticePage->assertDontSee('Set account DNS template');
    if($canEdit){
        $templateEditor=$actor;
        $noticePage->assertSee('DNS monitoring is not set up for 1 domain. Set a default DNS template on the registrar account.');
        expect(str_contains($noticePage->html(),e(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$d->server_id]))),'Setup action opens this domain registrar account');
    }
    $page=Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$id]);
    $canEdit?$page->assertSee('Set account DNS template'):$page->assertDontSee('Set account DNS template');
    if($canEdit){
        $url=\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$d->server_id]).'#switch-template';
        $page->assertSee('href="'.e($url).'">Set account DNS template',false);
    }
}
Livewire::actingAs($viewer)->test(ListWatches::class)->assertSee('DNS monitoring is not set up for 1 domain.')
    ->assertDontSee('Set account DNS template');

DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['enabled'=>false]);
Livewire::actingAs($templateEditor)->test(ListManagedDns::class)->assertTableActionHidden('assignTemplate',$d)->assertTableActionVisible('accountTemplate',$d);
DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['enabled'=>true]);
$setupPage=Livewire::actingAs($manager)->test(ListManagedDns::class);
$mounted=Livewire::actingAs($manager)->test(ListManagedDns::class)->assertCanSeeTableRecords([$d])->mountTableAction('assignTemplate',$d);
$assignmentHtml=$mounted->html();
$ticket=StaffManagedDnsSetup::ticket($manager,$d);$writes=new StaffManagedDnsSetup();
function setupRefused(Closure $call): void {try{$call();}catch(\DomainException|\Symfony\Component\HttpKernel\Exception\HttpException){return;}throw new RuntimeException('Expected setup refusal');}
setupRefused(fn()=>$writes->assign($viewer,$id,$ticket,$profile->id,null));
$manager->role->update(['permissions'=>[Staff::VIEW,StaffWatchWrites::VIEW]]);
$before=WatchAudit::count();$mounted->setTableActionData(['profile'=>$profile->id])->callMountedTableAction();same($before,WatchAudit::count(),'Revoked mounted action cannot assign');
$manager->role->update(['permissions'=>[Staff::VIEW,StaffWatchWrites::VIEW,StaffWatchWrites::MANAGE]]);
Livewire::actingAs($manager)->test(ListManagedDns::class)->callTableAction('assignTemplate',$d,['profile'=>$profile->id,'expected_dns'=>'external'])->assertNotified('DNS template assigned');
same((int)$profile->id,(int)ManagedPublicConfig::find($id)->profile_id,'Staff action uses tested writer');
expect(!$m->health($now)['outage'],'Assignment does not inherit an old setup-only outage clock');
same(0,ManagedDnsSetup::affected($now)->count(),'Assigned domain leaves the filtered list');same(1,count((new ManagedPublicReconciliation($signals))->due($now)),'Domain without hosting queues only nameserver checks');
// A retained enabled template can be unreadable even though the editor refuses that data.
$storedNameservers=DB::table('domains_watch_profiles')->where('id',$profile->id)->value('nameservers');
DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['nameservers'=>'null']);
expect($profile->fresh()->enabled && $profile->fresh()->readProfile()===null,'Retained enabled template is unreadable');
same(1,ManagedDnsSetup::affected($now)->count(),'Unreadable template returns to setup count');
Livewire::actingAs($viewer)->test(ListManagedDns::class)->assertCanSeeTableRecords([$d]);
Livewire::actingAs($templateEditor)->test(ListWatches::class)->assertSee('DNS monitoring is not set up for 1 domain. Set a default DNS template on the registrar account.');
$unreadableSummary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->one($owner,'managed',$id,$now);
expect(in_array('domain.dns.not_configured',$unreadableSummary->stateKeys,true),'Customer sees unreadable template as not configured');
same([], (new ManagedPublicReconciliation($signals))->due($now),'Unreadable template has no configured runnable work');
DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['nameservers'=>$storedNameservers]);
$audit=WatchAudit::where('event','dns_template_assigned')->latest('id')->firstOrFail();same((int)$manager->id,(int)$audit->actor_id,'Assignment audit credits staff');same('staff',$audit->data['source'],'Assignment audit records source');
setupRefused(fn()=>$writes->assign($manager,$id,$ticket,$profile->id,null));
// Repair an existing null assignment from a current predecessor, independently of the ignored global default.
DB::transaction(function()use($id){$c=ManagedPublicConfig::find($id);$c->profile_id=null;$c->expected_dns=null;$c->revision++;$c->save();});
DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->update(['safe_code'=>'profile_unavailable','attempts'=>4]);
expect(!$m->health($now)['outage'],'Retained profile-unavailable work is excluded before repair');
$signals->enqueue($id,$uid,$now);
same([], (new ManagedPublicReconciliation($signals))->due($now),'Existing unconfigured work is not runnable');
same(0,(int)DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->max('attempts'),'Setup absence clears retained failure attempts');
$watch=DB::transaction(fn()=>WatchName::create(['user_id'=>$uid,'canonical_name'=>$d->domain,'psl_revision'=>str_repeat('a',64),'state'=>'linked','managed_domain_id'=>$id,'ns_profile_id'=>$profile->id,'expected_dns'=>'external','notice_preferences'=>[]]));
expect($signals->enqueue($id,$uid,$now),'Existing null config repaired from predecessor');same((int)$profile->id,(int)ManagedPublicConfig::find($id)->profile_id,'Current predecessor template inherited');same('external',ManagedPublicConfig::find($id)->expected_dns,'External intent preserved');
same('predecessor_watch',WatchAudit::where('event','dns_template_assigned')->latest('id')->first()->data['source'],'Automatic repair audited');
// A hosting mapping is proof only while its owner, field and map revision still match.
DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'retired','ns_profile_id'=>null]);
$hostingServer=\App\Models\Server::create(['name'=>'Synthetic hosting','extension'=>'SyntheticHosting','type'=>'server','enabled'=>true]);
$hostingProduct=\App\Models\Product::create(['name'=>'Synthetic hosting','slug'=>'dns-setup-host','category_id'=>$product->category_id,'server_id'=>$hostingServer->id,'hidden'=>true,'allow_quantity'=>'combined']);
$hostingPlan=$hostingProduct->plans()->create(['name'=>'Monthly','type'=>'recurring','billing_period'=>1,'billing_unit'=>'month']);
$hostingOrder=\App\Models\Order::create(['user_id'=>$uid,'currency_code'=>'USD']);
$hostingService=\App\Models\Service::create(['user_id'=>$uid,'order_id'=>$hostingOrder->id,'product_id'=>$hostingProduct->id,'plan_id'=>$hostingPlan->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
$hostingService->properties()->create(['key'=>'domain','value'=>$d->domain]);
$map=DB::transaction(fn()=>WatchProductMap::create(['product_id'=>$hostingProduct->id,'property_key'=>'domain','expected_ns_profile_id'=>$profile->id,'edited_by'=>$manager->id,'reason'=>'Synthetic expected DNS']));
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>true]);
DB::transaction(fn()=>WatchSource::create(['user_id'=>$uid,'service_id'=>$hostingService->id,'map_id'=>$map->id,'map_revision'=>$map->revision,'hostname'=>$d->domain,'registrable_name'=>$d->domain,'managed_domain_id'=>$id,'state'=>'managed_link','source_digest'=>str_repeat('b',64),'last_seen_at'=>$now]));
$clear=function(?string $expected)use($id):void{DB::transaction(function()use($id,$expected){$c=ManagedPublicConfig::find($id);$c->profile_id=null;$c->expected_dns=$expected;$c->revision++;$c->save();});};
$clear('external');$signals->enqueue($id,$uid,$now);same(null,ManagedPublicConfig::find($id)->profile_id,'Hosting mapping never overrides explicit external DNS intent');
$clear(null);DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['revision'=>$map->revision+1]);
$signals->enqueue($id,$uid,$now);same(null,ManagedPublicConfig::find($id)->profile_id,'Stale hosting mapping cannot establish intent');
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['revision'=>$map->revision]);
// Conflicting current maps require staff intent, even when each map is otherwise valid.
$source=WatchSource::where('managed_domain_id',$id)->where('state','managed_link')->firstOrFail();
$ambiguous=clone $source;$ambiguous->map_id=9999;$otherMap=$map->fresh();$otherMap->expected_ns_profile_id=$profile->id+1;
$proof=DB::transaction(function()use($d,$hostingService,$source,$ambiguous,$map,$otherMap):array {
    $locks=\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($d,additionalServices:[$hostingService->id]);
    return ManagedDnsSetup::proven($locks,null,ManagedPublicConfig::find($d->id),collect([$source,$ambiguous]),[$map->id=>$map->fresh(),9999=>$otherMap]);
});
same(null,$proof[0],'Ambiguous hosting templates never choose arbitrarily');
$authority=$d->fresh()->getRawOriginal();$providerCalls=count($wire->calls);
$signals->enqueue($id,$uid,$now);same((int)$profile->id,(int)ManagedPublicConfig::find($id)->profile_id,'Unambiguous current hosting product repairs existing config');
same('hosting_product',WatchAudit::where('event','dns_template_assigned')->latest('id')->first()->data['source'],'Hosting proof audited');
same($authority,$d->fresh()->getRawOriginal(),'Setup never changes customer DNS or holding authority');same($providerCalls,count($wire->calls),'Setup makes no registrar calls');
// Assigning one domain must not hide an existing failure on another configured domain.
$otherDomain=$d->fresh()->getRawOriginal();$otherDomain['id']=9999;$otherDomain['service_id']=9999;$otherDomain['domain']='other.example.com';$otherDomain['reservation_name']='other.example.com';
DB::table('domains_domains')->insert($otherDomain);
DB::table('domains_managed_public_config')->insert(['domain_id'=>9999,'user_id'=>$uid,'profile_id'=>$profile->id,'notice_preferences'=>'{}']);
DB::table(ManagedPublicWorkSchema::TABLE)->insert(['domain_id'=>9999,'user_id'=>$uid,'generation'=>$d->authority_generation,'account_fingerprint'=>$d->account_fingerprint,'kind'=>'delegation','due_at'=>$now,'attempts'=>1,'safe_code'=>'resolver_failed','target_stamp'=>str_repeat('a',64)]);
DB::table('domains_watch_schedule')->where('scope','health_managed_dns')->update(['cursor'=>$now-90000]);
DB::transaction(fn()=>ManagedDnsSetup::assign(ManagedPublicConfig::find($id),$profile,'platform',null,'hosting_product',$now));
same($now-90000,(int)DB::table('domains_watch_schedule')->where('scope','health_managed_dns')->value('cursor'),'Other pending domain keeps its outage clock');
DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',9999)->delete();DB::table('domains_managed_public_config')->where('domain_id',9999)->delete();DB::table('domains_domains')->where('id',9999)->delete();
// Real transport failures retain a red alert, a safe cause, and an affected-checks page.
DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->update(['attempts'=>1,'safe_code'=>'resolver_failed','due_at'=>$now]);
DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$now+90000]);
$health=$m->health($now+90000);expect($health['outage'],'Configured resolver failures still alert');same('resolver_failed',$health['resolver_error'],'Only normalized resolver cause is exposed');
expect(str_contains(WatchMaintenance::notice($health)['text'],"DNS checks can't reach the resolver."),'Resolver alert identifies actual transport failure');
config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>180]);
$time->now=$now+90000;
foreach(['resolver_failed'=>'The resolver request failed.','deadline'=>'The resolver did not reply in time.']as$code=>$cause){
    DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->update(['safe_code'=>$code]);
    $failureNotice=Livewire::actingAs($viewer)->test(ListWatches::class)->assertSee('Resolver settings')->assertSee('Affected checks')
        ->assertSee("DNS checks can't reach the resolver. Recent error: ".$cause.' Retrying automatically.')->assertDontSee('Last error:');
}
DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->update(['safe_code'=>'resolver_failed']);
$failurePage=Livewire::actingAs($manager)->test(ListDnsFailures::class)->assertCanSeeTableRecords([$d])->assertTableActionHidden('assignTemplate',$d);
// The staff monitoring page must name local/product failures beside a resolver outage.
DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['data'=>json_encode(['local_failures'=>['products'=>$now,'discovery'=>$now]])]);
Livewire::actingAs($viewer)->test(ListWatches::class)->assertSee('DNS checks and Domain products and Domain discovery have failed since ')
    ->assertDontSee("DNS checks can't reach the resolver.")->assertDontSee('Resolver settings');
DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['data'=>null]);
if(isset($argv[1])){
    $css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
    foreach(['setup'=>$setupPage->html(),'assign'=>$assignmentHtml,'notice'=>$noticePage->html(),'failures'=>$failurePage->html(),'resolver'=>$failureNotice->html()]as$name=>$body){
        $body=preg_replace('~<script\\b[^>]*>.*?</script>~si','',$body);
        $body=str_replace('class="fi-modal fi-absolute-positioning-context"','class="fi-modal fi-modal-open fi-absolute-positioning-context"',$body);
        $html='<!doctype html><html class="dark"><head><meta charset="utf-8"><style>'.$css.'body{padding:2rem;background:#111827;color:#f3f4f6}*{animation:none!important;transition:none!important}</style></head><body>'.$body.'</body></html>';
        expect(file_put_contents($argv[1].'/dns-'.$name.'.html',$html)===strlen($html),'Render saved');
    }
}
$nativeSuccessful=true;echo "Missing comparison setup, calm notice, ownership-filtered count, staff assignment, predecessor repair, audit and resolver failure passed.\n";
