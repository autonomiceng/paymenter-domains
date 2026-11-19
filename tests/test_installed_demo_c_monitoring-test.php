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
foreach(['192.0.2.10','192.0.2.20','Public DNS','Registrar records',e($e['separation']),'Registrar refresh'] as $value){expect(str_contains($html,$value),'Evidence renders '.$value);}
// Times are instants, so compare the moments the page names, not how one is written.
$instants=static fn(string $html):array=>array_map('strtotime',preg_match_all('/<time datetime="([^"]+)"/',$html,$m)?$m[1]:[]);
expect(in_array(strtotime('2026-11-05T09:00:00Z'),$instants($html),true),'Evidence names the registrar read instant');
same('Partial',$e['state'],'Independent incomplete registrar families stay Partial');
$manager=staffUser([Staff::VIEW,Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::VIEW,Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList::VIEW]);Auth::login($manager);
$history=WatchFacts::history($watch);expect(in_array('Registrar read partial',array_column($history,'event'),true)&&in_array('Registrar read complete',array_column($history,'event'),true),'Monitoring history contains actual current and last-complete registrar reads');
$otherWatch=clone $watch;$otherWatch->user_id=$other->id;same([],MonitoringEvidence::history($otherWatch),'Another owner cannot join this registrar evidence');
staffPanel();$panel=Filament::getCurrentPanel();$resources=[WatchResource::class,Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource::class];$panel->resources($resources);Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resources,$panel){foreach($resources as $resource){$resource::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$html=Livewire\Livewire::test(ViewWatch::class,['record'=>$watch->id])->html();expect(str_contains($html,'192.0.2.10')&&str_contains($html,'192.0.2.20'),'Real monitoring detail renders both observations');
$html=Livewire\Livewire::test(WatchHistory::class,['record'=>$watch->id])->html();expect(str_contains($html,'Registrar read partial'),'Real monitoring history renders registrar reads');
$html=Livewire\Livewire::test(Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\MonitoringSettings::class)->html();
same([\Paymenter\Extensions\Servers\Domains\Presentation\Plain::Daily->label(),\Paymenter\Extensions\Servers\Domains\Presentation\Plain::None->label()],[settingsRowValue($html,'Registrar refresh')['word']??null,settingsRowValue($html,'Registrar read failure emails')['word']??null],'Real settings shows daily reads and no alert switch for read failures');
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
// Freshness is data: saved expired answers keep their address and check instant, but read differently from fresh ones.
$Value=static fn(array $e)=>MonitoringEvidence::rows($e)['Public DNS'];
$expired=MonitoringEvidence::read($d,$managed);$stale=$Value($expired);$fresh=$Value([...$expired,'public_stale'=>false]);$tried=gmdate('Y-m-d\\TH:i:s\\Z',$at->getTimestamp()-60);
expect($expired['public_stale']&&$stale->fact?->items===['A @ → 192.0.2.10']&&$stale->subAt?->datetime===$tried&&$stale->sub!==$fresh->sub,'Saved expired IP answers keep their check time and do not read as current');
// A failed resolver read saves a time and no answers. It never reads as None or as checked, fresh or expired.
$noAnswer=static function (string $state, ?string $code, int $until, array $types = ['A']) use ($d,$at) {
    $queries = array_map(static fn (string $type): array => ['name'=>'example.com','type'=>$type,'state'=>$state,'records'=>[],'code'=>$code], $types);
    return new Paymenter\Extensions\Servers\Domains\Models\ManagedPublicObservation(['domain_id'=>$d->id,'user_id'=>$d->user_id,'generation'=>$d->authority_generation,'account_fingerprint'=>$d->account_fingerprint,'domain_revision'=>$d->confirmed_revision,'check_kind'=>'points','started_at'=>$at->getTimestamp()-60,'valid_until'=>$until,'result'=>$state==='negative'?'complete':'unknown','facts'=>['version'=>1,'queries'=>$queries]]);
};
foreach([$at->getTimestamp()+60,$at->getTimestamp()-1] as $until){
    $read=MonitoringEvidence::read($d,$noAnswer('unknown','resolver_failed',$until));$failed=$Value($read);
    expect($read['public_stale']&&$failed->state===Paymenter\Extensions\Servers\Domains\Presentation\State::CouldntRead&&$failed->word!==Paymenter\Extensions\Servers\Domains\Presentation\Plain::None->label(),'A failed resolver read is Couldn\'t read, never None');
    expect($failed->subAt?->datetime===$tried,'A failed resolver read keeps when it was tried');
    expect($failed->sub!==$Value([...$read,'public_stale'=>false])->sub,'An outdated failed public read remains explicitly historical');
}
// Type-specific absence needs both families. Once expired it is historical, like saved answers.
$onlyA=$Value(MonitoringEvidence::read($d,$noAnswer('negative',null,$at->getTimestamp()-1)));
same(Paymenter\Extensions\Servers\Domains\Presentation\State::CouldntRead,$onlyA->state,'An A-only negative cannot establish whole-host absence');
$confirmedFresh=$Value(['public_read'=>'negative','public'=>[],'public_at'=>$at->getTimestamp()-60,'public_stale'=>false]);
$confirmedStale=$Value(MonitoringEvidence::read($d,$noAnswer('negative',null,$at->getTimestamp()-1,['A','AAAA'])));
same([Paymenter\Extensions\Servers\Domains\Presentation\Plain::None->label(),Paymenter\Extensions\Servers\Domains\Presentation\Plain::None->label()],[$confirmedFresh->word,$confirmedStale->word],'A confirmed negative public read is None');
expect($confirmedStale->sub===$stale->sub&&$confirmedFresh->sub===$fresh->sub,'An empty public result keeps the same fresh or stale reading as answers');
// The registrar side says None only after a complete DNS read.
$registrar=static fn(?string $state)=>MonitoringEvidence::rows(['registrar'=>[],'registrar_at'=>'2026-11-05T09:00:00+00:00','registrar_read'=>$state])['Registrar records'];
same([Paymenter\Extensions\Servers\Domains\Presentation\Plain::None->label(),Paymenter\Extensions\Servers\Domains\Presentation\State::CouldntRead,Paymenter\Extensions\Servers\Domains\Presentation\State::CouldntRead],[$registrar('complete')->word,$registrar('partial')->state,$registrar('unknown')->state],'Registrar records say None only after a complete DNS read');
// A first registrar read that failed has no records and no record time: the row is Couldn't read at the failure time, not Not read yet.
$first=clone $d;$first->observation=['record_failure'=>['identity'=>ObservationRecords::identity($d),'code'=>'domain_not_in_account','at'=>'2026-11-05T10:00:00+00:00']];
$failedFirst=MonitoringEvidence::rows(MonitoringEvidence::read($first))['Registrar records'];
same(Paymenter\Extensions\Servers\Domains\Presentation\State::UnderReview,$failedFirst->state,'A definite first absence is under review');
same(strtotime('2026-11-05T10:00:00Z'),$instants($failedFirst->toHtml())[0],'A failed first registrar read names when it was tried');
// A later failed read keeps the old inventory, but its attempt time belongs to the later failure.
$later=clone $d;$later->observation=[...$d->observation,'record_failure'=>$first->observation['record_failure']];
$laterEvidence=MonitoringEvidence::read($later);$laterRow=MonitoringEvidence::rows($laterEvidence)['Registrar records'];
same($e['registrar'],$laterEvidence['registrar'],'A failed refresh retains the saved registrar addresses');
same(strtotime($first->observation['record_failure']['at']),$instants($laterRow->toHtml())[0],'A failed refresh names the latest attempt, not the retained inventory time');
// The linked watch distinguishes a failed registrar attempt from a complete read at the same instant.
$d->observation=$later->observation;$d->saveQuietly();
$attempt=WatchFacts::for($watch->fresh(),['summary'=>null,'keys'=>[]])['Evidence']['Registrar records'];
same('unknown',$laterEvidence['registrar_read'],'A failed refresh leaves the registrar read unknown');
same($e['registrar'],explode('; ',$attempt['value']),'The watch retains the saved registrar addresses after a failed refresh');
same([strtotime($first->observation['record_failure']['at'])],$instants((string)$attempt['sub']),'The watch names the failed attempt instant');
$complete=$d->observation;unset($complete['record_failure']);$complete['records']['at']=$first->observation['record_failure']['at'];
$d->observation=$complete;$d->saveQuietly();
$checked=WatchFacts::for($watch->fresh(),['summary'=>null,'keys'=>[]])['Evidence']['Registrar records'];
same($instants((string)$checked['sub']),$instants((string)$attempt['sub']),'The control has the same registrar instant');
expect((string)$attempt['sub']!==(string)$checked['sub'],'An unfinished registrar attempt is distinguished from a successful read');
$d->observation=$later->observation;$d->saveQuietly();
// Addresses from a registrar read that did not finish stay listed, with the same unfinished words and time as an empty one.
$listed=static fn(?string $state)=>MonitoringEvidence::rows(['registrar'=>['A @ → 192.0.2.20'],'registrar_at'=>'2026-11-05T09:00:00+00:00','registrar_read'=>$state])['Registrar records'];
foreach(['partial','unknown'] as $state){$unfinished=$listed($state);
    expect($unfinished->fact==$listed('complete')->fact&&$unfinished->sub===$registrar($state)->sub&&$unfinished->sub!==$listed('complete')->sub,'Registrar addresses from an unfinished read keep the unfinished reading: '.$state);
    same(strtotime('2026-11-05T09:00:00Z'),strtotime((string)$unfinished->subAt?->datetime),'Unfinished registrar addresses keep the read time: '.$state);}
// A stored public observation can exist without a selected template.
DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>null,'expected_dns'=>null]);$watch->unsetRelation('delegation');
DB::transaction(fn()=>WatchDnsObservation::create(['watch_id'=>$watch->id,'user_id'=>$owner->id,'watch_revision'=>$watch->revision,'psl_revision'=>$watch->psl_revision,'mixed'=>false,'profile_id'=>$profile->id,'profile_revision'=>$profile->revision,'check_kind'=>'delegation','started_at'=>$at->getTimestamp()-30,'completed_at'=>$at->getTimestamp()-29,'result'=>'complete','partial'=>false,'valid_until'=>$at->getTimestamp()+60,'dns'=>'platform','facts'=>['version'=>1,'queries'=>[['name'=>'example.com','type'=>'NS','state'=>'rrset','records'=>[['value'=>'ns1.platform.test']]]]],'digest'=>str_repeat('d',64),'source_digest'=>str_repeat('e',64)]));
$missingFacts=WatchFacts::for($watch->fresh());
same(null,$missingFacts['Domain']['DNS template']['value'],'Fixture has no DNS template');
expect($missingFacts['Checks']['Nameservers']['sub']!=='Matches template','No template cannot establish a nameserver match');
expect(str_contains($missingFacts['Checks']['Nameservers']['sub']??'','template'),'Missing comparison says how to configure it');
// No template means no comparison, not no public evidence. Keep the same saved A/AAAA answers and observation time.
$recordFact=$missingFacts['Checks']['DNS records']??['value'=>null,'sub'=>null];
same('A @ → 192.0.2.10',$recordFact['value'],'DNS records shows stored public answers without a template');
// ui-r15 R2: the check time is a <time> in the viewer's zone, so compare its datetime.
expect(str_contains((string)($recordFact['sub']??''),'template'),'Unconfigured comparison explains the next step');
// Last check is the later nameserver check in the same minute, a different check, so the row keeps the saved answer time.
same($at->getTimestamp()-30,(int)($missingFacts['Checks']['Last check']['at']??0),'Last check is the separate nameserver check');
same([gmdate('c',$at->getTimestamp()-60)],array_map(fn($n)=>$n->getAttribute('datetime'),iterator_to_array(storefrontDom('<div id="records-sub">'.($recordFact['sub']??'').'</div>')->query('//*[@id="records-sub"]//time'))),'DNS records keeps the saved answer time');
$html=Livewire\Livewire::test(ViewWatch::class,['record'=>$watch->id])->html();
$x=storefrontDom($html);$row=$x->query('//*[@data-piece="row"][*[1][normalize-space(.)="DNS records"]]')->item(0);
$unread=array_map(fn($s)=>$s->label(),[\Paymenter\Extensions\Servers\Domains\Presentation\State::NotReadYet,\Paymenter\Extensions\Servers\Domains\Presentation\State::CouldntRead]);
expect($row!==null&&str_contains($row->textContent,'192.0.2.10')&&!array_filter($unread,fn($w)=>str_contains($row->textContent,$w)),'Real monitoring row renders the saved public record');
// Expired watch answers remain historical, with guidance for the actual template state.
$dnsFailures=[];
$public->valid_until=$at->getTimestamp()-1;
foreach([null,$profile->id] as $profileId){
    $candidate=$watch->fresh();$candidate->ns_profile_id=$profileId;$candidate->setRelation('points',$public);
    $facts=WatchFacts::for($candidate,['summary'=>null,'keys'=>[]]);
    $record=$facts['Checks']['DNS records']??['value'=>null,'sub'=>null];
    if($profileId!==null&&str_contains((string)($record['sub']??''),'Choose a DNS template')){$dnsFailures[]='An existing template must not be described as missing';}
}
// A linked watch without its own points read must not adopt a managed read as its Latest check.
$managed->domain_revision=(int)$d->fresh()->confirmed_revision;$managed->config_revision=1;$managed->profile_id=$profile->id;$managed->profile_revision=$profile->revision;$managed->mixed=false;$managed->completed_at=$at->getTimestamp()-59;$managed->partial=false;$managed->facts=['version'=>1,...$public->facts];$managed->digest=str_repeat('a',64);$managed->source_digest=str_repeat('b',64);
DB::transaction(fn()=>$managed->save());
$candidate=$watch->fresh();$candidate->setRelation('points',null);
$facts=WatchFacts::for($candidate,['summary'=>null,'keys'=>[]]);
if(($facts['Checks']['DNS records']['value']??null)==='A @ → 192.0.2.10'){$dnsFailures[]='Latest checks must not borrow managed public DNS';}
same('A @ → 192.0.2.10',$facts['Evidence']['Public DNS']['value'],'Managed answers remain available under Evidence');
expect($dnsFailures===[],implode('; ',$dnsFailures));
// A retained watch read from before presentation stamps still has its worker scope and read time.
$attempt = $public->replicate()->fill(['result'=>'unknown','partial'=>true,'valid_until'=>null,'started_at'=>$at->getTimestamp(),
    'completed_at'=>$at->getTimestamp()+1,'facts'=>['version'=>1,'queries'=>[]],'safe_code'=>'resolver_failed']);
DB::transaction(fn()=>$attempt->save());
$retained = WatchFacts::for($watch->fresh(),['summary'=>null,'keys'=>[]])['Evidence']['Public DNS'];
same('A @ → 192.0.2.10',$retained['value'],'A failed watch refresh preserves the original public address');
same([$public->started_at],$instants((string)$retained['sub']),'The address stays dated to the earlier watch observation');
$nativeSuccessful=true;echo "Demo C monitoring evidence passed.\n";
