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
expect(str_contains($html,'Sync now')&&str_contains($html,'domain facts'),'Settings distinguishes daily facts from manual record sync');
// Outdated saved answers remain evidence of a past check, never current IP answers.
foreach(['expired','revision','failed'] as $case){
    $old=clone $public;
    if($case==='expired'){$old->valid_until=$at->getTimestamp()-1;}
    elseif($case==='revision'){$old->watch_revision=$watch->revision+1;}
    else{$old->result='unknown';$old->safe_code='read_failed';}
    expect(MonitoringEvidence::read($d,$old,'example.com')['public_stale']??false,'Outdated watch DNS answers are historical: '.$case);
}
$managed=new Paymenter\Extensions\Servers\Domains\Models\ManagedPublicObservation(['domain_id'=>$d->id,'user_id'=>$d->user_id,'generation'=>$d->authority_generation,'account_fingerprint'=>$d->account_fingerprint,'domain_revision'=>$d->confirmed_revision,'check_kind'=>'points','started_at'=>$at->getTimestamp()-60,'valid_until'=>$at->getTimestamp()-1,'result'=>'complete','facts'=>$public->facts]);
expect(MonitoringEvidence::read($d,$managed)['public_stale']??false,'Expired managed public answers are historical');
$historical=view('domains::admin.monitoring-evidence',['evidence'=>MonitoringEvidence::read($d,$managed)])->render();
expect(str_contains($historical,'Historical answers'),'Saved expired IP answers have an explicit historical label');
// A stored public observation can exist without a selected template.
DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>null,'expected_dns'=>null]);$watch->unsetRelation('delegation');
DB::transaction(fn()=>WatchDnsObservation::create(['watch_id'=>$watch->id,'user_id'=>$owner->id,'watch_revision'=>$watch->revision,'psl_revision'=>$watch->psl_revision,'mixed'=>false,'profile_id'=>$profile->id,'profile_revision'=>$profile->revision,'check_kind'=>'delegation','started_at'=>$at->getTimestamp()-30,'completed_at'=>$at->getTimestamp()-29,'result'=>'complete','partial'=>false,'valid_until'=>$at->getTimestamp()+60,'dns'=>'platform','facts'=>['version'=>1,'queries'=>[['name'=>'example.com','type'=>'NS','state'=>'rrset','records'=>[['value'=>'ns1.platform.test']]]]],'digest'=>str_repeat('d',64),'source_digest'=>str_repeat('e',64)]));
$missingFacts=WatchFacts::for($watch->fresh());
same(null,$missingFacts['Domain']['DNS template']['value'],'Fixture has no DNS template');
expect($missingFacts['Checks']['Nameservers']['sub']!=='Matches template','No template cannot establish a nameserver match');
expect(str_contains($missingFacts['Checks']['Nameservers']['sub']??'','template'),'Missing comparison says how to configure it');
// Saved answers need no template. Same-minute timestamps belong in Last check only;
// observations from an earlier minute retain their own exact time in both facts and rendered rows.
foreach ([60=>false,120=>true] as $age=>$showTime) {
    $observedAt=$at->getTimestamp()-$age;
    DB::table($public->getTable())->where('id',$public->id)->update(['started_at'=>$observedAt,'completed_at'=>$observedAt+1]);
    $saved=$watch->fresh();$facts=WatchFacts::for($saved);
    $recordFact=$facts['Checks']['DNS records'];
    same('A @ → 192.0.2.10',$recordFact['value'],'DNS records shows stored public answers without a template');
    same($observedAt,MonitoringEvidence::read($d,$saved->points,'example.com')['public_at'],'Evidence keeps the actual observation instant');
    same($at->getTimestamp()-30,$facts['Checks']['Last check']['at'],'Last check retains the newer delegation instant');
    $sub=(string)$recordFact['sub'];
    expect(str_contains($sub,'Choose a DNS template to compare these records.'),'Unconfigured comparison explains the next step');
    $datetime='datetime="'.gmdate('c',$observedAt).'"';
    if ($showTime) {
        expect(str_contains($sub,$datetime),'Earlier-minute DNS records retains the exact observation time');
        expect(str_contains((string)$facts['Evidence']['Public DNS']['sub'],$datetime),'Earlier-minute public evidence retains the exact observation time');
    } else {
        same('Choose a DNS template to compare these records.',$sub,'Same-minute DNS records omits the redundant Checked time');
        expect(!str_contains((string)$facts['Evidence']['Public DNS']['sub'],'<time'),'Same-minute public evidence omits the redundant Checked time');
    }
    $html=Livewire\Livewire::test(ViewWatch::class,['record'=>$watch->id])->html();
    $x=storefrontDom($html);$rows='//*[contains(concat(" ",normalize-space(@class)," ")," domains-row ")]';
    $row=$x->query($rows.'[.//*[normalize-space(.)="DNS records"]]')->item(0);
    expect($row!==null&&str_contains($row->textContent,'192.0.2.10')&&!str_contains($row->textContent,'Missing'),'Real monitoring row renders the saved public record');
    $times=$x->query('.//time',$row);
    same($showTime?1:0,$times->length,'Rendered DNS records repeats its time only for an earlier minute');
    if ($showTime) { same(gmdate('c',$observedAt),$times->item(0)->getAttribute('datetime'),'Rendered DNS records uses its actual observation time'); }
    $last=$x->query($rows.'[.//*[normalize-space(.)="Last check"]]//time')->item(0);
    expect($last!==null,'Real Last check renders a time');
    same(gmdate('c',$at->getTimestamp()-30),$last->getAttribute('datetime'),'Real Last check keeps the newer delegation time');
}
$nativeSuccessful=true;echo "Demo C monitoring evidence passed.\n";
