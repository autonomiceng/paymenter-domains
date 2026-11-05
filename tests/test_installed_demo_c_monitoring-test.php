<?php
// Native linked monitoring evidence and read history. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';require __DIR__.'/Support/storefront.php';
use Illuminate\Support\Facades\{Auth,DB,Route};
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,WatchName,WatchDnsObservation};
use Paymenter\Extensions\Servers\Domains\Presentation\MonitoringEvidence;
use Paymenter\Extensions\Servers\Domains\Services\ObservationRecords;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ViewWatch,WatchHistory};
extract(dnsFixture());storefrontViews();
$at=new DateTimeImmutable('2026-11-05T12:00:00+00:00');app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new class($at) implements Paymenter\Extensions\Servers\Domains\Contracts\Clock{public function __construct(private DateTimeImmutable $at){}public function now():DateTimeImmutable{return $this->at;}});
$registrarServer=App\Models\Server::create(['name'=>'Synthetic registrar','extension'=>'Domains','type'=>'server','enabled'=>true]);
$account=Paymenter\Extensions\Servers\Domains\Models\Account::withVerifiedBindingWrite(fn()=>Paymenter\Extensions\Servers\Domains\Models\Account::create(['server_id'=>$registrarServer->id,'registrar_key'=>'namesilo','environment'=>'test','stable_identity'=>'SYNTHETIC-MONITORING']));
$binding=['server_id'=>$registrarServer->id,'registrar_key'=>'namesilo','account_fingerprint'=>$account->account_fingerprint];
$d=Domain::create([...$binding,'domain'=>'example.com','sld'=>'example','tld'=>'com','user_id'=>$owner->id,'service_id'=>$service->id,'authority_generation'=>1,'status'=>'active','observation'=>[]]);
$d->update(['observation'=>['records'=>['identity'=>ObservationRecords::identity($d),'at'=>'2026-11-05T09:00:00+00:00','last_complete_at'=>'2026-11-04T09:00:00+00:00','sequence'=>0,'format'=>1,'capability_revision'=>'namesilo-records-1','expected_families'=>['dns'=>'supported','srv'=>'unsupported','ds'=>'supported','glue'=>'supported'],
'families'=>['dns'=>['state'=>'complete','records'=>[['name'=>'@','type'=>'A','address'=>'192.0.2.20','mx_pref'=>10,'record_id'=>'1','ttl'=>3600]]],'srv'=>['state'=>'unknown','records'=>[]],'ds'=>['state'=>'unknown','records'=>[]],'glue'=>['state'=>'partial','records'=>[]]],'settings'=>[]]]]);
DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'linked','managed_domain_id'=>$d->id,'registration'=>'platform']);$watch->refresh();
$public=DB::transaction(fn()=>WatchDnsObservation::create(['watch_id'=>$watch->id,'user_id'=>$owner->id,'watch_revision'=>$watch->revision,'profile_id'=>$profile->id,'profile_revision'=>$profile->revision,'psl_revision'=>$watch->psl_revision,'mixed'=>false,'check_kind'=>'points','started_at'=>$at->getTimestamp()-60,'completed_at'=>$at->getTimestamp()-59,'result'=>'complete','partial'=>false,'valid_until'=>$at->getTimestamp()+60,'facts'=>['version'=>1,'queries'=>[['name'=>'example.com','type'=>'A','records'=>[['value'=>'192.0.2.10']]]]],'digest'=>str_repeat('b',64),'source_digest'=>str_repeat('c',64)]));
$e=MonitoringEvidence::read($d,$public,'example.com');$html=view('domains::admin.monitoring-evidence',['evidence'=>$e])->render();
foreach(['192.0.2.10','192.0.2.20','Public DNS','Registrar records','Separate checks','Registrar refresh','2026-11-05T09:00:00+00:00'] as $value){expect(str_contains($html,$value),'Evidence renders '.$value);}
same('Partial',$e['state'],'Independent incomplete registrar families stay Partial');
$manager=staffUser([Staff::VIEW,Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::VIEW,Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList::VIEW]);Auth::login($manager);
$history=WatchFacts::history($watch);expect(in_array('Registrar read partial',array_column($history,'event'),true)&&in_array('Registrar read complete',array_column($history,'event'),true),'Monitoring history contains actual current and last-complete registrar reads');
$otherWatch=clone $watch;$otherWatch->user_id=$other->id;same([],MonitoringEvidence::history($otherWatch),'Another owner cannot join this registrar evidence');
staffPanel();$panel=Filament::getCurrentPanel();$resources=[WatchResource::class,Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource::class];$panel->resources($resources);Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resources,$panel){foreach($resources as $resource){$resource::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$html=Livewire\Livewire::test(ViewWatch::class,['record'=>$watch->id])->html();expect(str_contains($html,'192.0.2.10')&&str_contains($html,'192.0.2.20')&&str_contains($html,'Evidence'),'Real monitoring detail renders both observations');
$html=Livewire\Livewire::test(WatchHistory::class,['record'=>$watch->id])->html();expect(str_contains($html,'Registrar read partial'),'Real monitoring history renders registrar reads');
$html=Livewire\Livewire::test(Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\MonitoringSettings::class)->html();expect(str_contains($html,'Daily')&&str_contains($html,'Registrar read failures')&&str_contains($html,'No separate alert switch'),'Real settings shows daily reads and the unavailable alert switch');
$nativeSuccessful=true;echo "Demo C monitoring evidence passed.\n";
