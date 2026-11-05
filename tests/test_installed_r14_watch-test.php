<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
require __DIR__.'/Support/FakeDnsReader.php';
use Illuminate\Support\Facades\{Auth,DB,Route,Blade};
use Paymenter\Extensions\Servers\Domains\Services\{WatchWork,WatchDiscoveryWork,WatchRegistryChecks,DomainSummaries};
use Paymenter\Extensions\Servers\Domains\Models\WatchDnsObservation;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ViewWatch;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts};
use Paymenter\Extensions\Servers\Domains\Presentation\WatchPage;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsQuery};
config(['domains.site_dns_default.nameservers'=>['ns1.beco.test','ns2.beco.test'],'settings.company_name'=>'Beco']);
extract(dnsFixture());
DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>null,'profile_revision'=>0,'expected_dns'=>null]);
$now=time()-1200;
config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>180]);
$fake=new \Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader(['example.com|NS'=>static fn($q)=>DnsAnswer::rrset($q,[['host'=>'example.com','class'=>'IN','type'=>'NS','target'=>'ns.cloudflare.test','ttl'=>900]])]);
$sent=[];$work=null;
$work=new WatchWork($fake,new WatchDiscoveryWork(static fn()=>null),true,static function()use(&$now){return $now;},static function($hint)use(&$sent,&$work){$sent[]=$hint;$work->observe($hint);});
app()->instance(WatchWork::class,$work);$work->enrollWatch($watch->id,$owner->id);
DB::table('domains_watch_work')->where('watch_id',$watch->id)->where('kind','expiry')->update(['due_at'=>$now+86400]);
// A real queue turn, inherited encrypted policy, confirmation interval and both renderers
// are needed to cover the call site missed by the direct planner test.
$start=$now;foreach([0,300,600,900,1200]as$offset){$now=$start+$offset;$work->tick();}
$last=WatchDnsObservation::where('watch_id',$watch->id)->where('check_kind','delegation')->latest('id')->first();
expect($last!==null&&$last->facts['ns_confirmed'],'Scheduled worker confirms the inherited mismatch');
expect(DB::table('domains_watch_work')->where('watch_id',$watch->id)->where('kind','delegation')->value('due_at')>$now,'Confirmation advances its due time');
same(null,$watch->fresh()->expected_dns,'Inheritance does not rewrite the watch');
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';staffPanel();
$panel=\Filament\Facades\Filament::getCurrentPanel();$panel->viteTheme([])->theme(new \Illuminate\Support\HtmlString(''));$panel->resources([WatchResource::class,RoutingProfileResource::class]);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel){WatchResource::registerRoutes($panel);RoutingProfileResource::registerRoutes($panel);});app('router')->getRoutes()->refreshNameLookups();
$manager=staffUser([Staff::VIEW,'admin.domains.watch.view']);Auth::setUser($manager);
$summary=(new DomainSummaries())->one($owner,'watch',$watch->id,$now);
$staff=WatchFacts::for($watch->fresh(),['keys'=>[],'summary'=>$summary]);
$customer=view('domains::partials.summary',['summary'=>$summary])->render();
writeRenderArtifact(sys_get_temp_dir().'/r14-watch-customer.html',$customer);
$errors=[];
if(!str_contains(json_encode($staff),'does not use Beco nameservers')){$errors[]='Staff does not explain inherited mismatch';}
if(str_contains(json_encode($staff),'Choose a DNS template to compare nameservers')){$errors[]='Staff asks for a template already inherited';}
if(!str_contains($customer,'does not use Beco nameservers')){$errors[]='Customer still waits on the notice planner';}
// Keep native page HTML even on red for the screenshot comparison.
\Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::createFromTimestampUTC($now));
$page=\Livewire\Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$watch->id]);
$html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$page->instance(),'body'=>$page->html()]);
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$html=preg_replace('~<script\b[^>]*>.*?</script>|<link\b[^>]*>~si','',$html);
writeRenderArtifact(sys_get_temp_dir().'/r14-watch-staff.html',str_replace('</head>','<style>'.$css.'</style>'.view('domains::partials.staff-styles')->render().'</head>',$html));
expect($errors===[],implode('; ',$errors));
$nativeSuccessful=true;echo "Scheduled inherited mismatch and native staff/customer copy passed.\n";
