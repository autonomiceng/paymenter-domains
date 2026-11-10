<?php
// Full native observation matrix; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/observation-records-ready.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationProjection,ObservationRecords,ObservationSchema,ObservationWork,OwnerRecords,ReadDeferred};
// Run the queued worker through the real observer and fake wire, then revoke its staff permission.
require_once __DIR__.'/Support/staff-panel.php';
$syncActor=staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,'admin.domains.manage']);
$syncJob=new \Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords($syncActor->id,$d->id,$d->authority_generation,$d->account_fingerprint);
$clock->instant=$clock->now()->modify('+61 seconds');$syncJob->handle($observer);
same($clock->now()->format(DATE_ATOM),$d->fresh()->observation['records']['at'],'Queued worker saves record inventory through the observer');
same('Domain synced',App\Models\Notification::where('user_id',$syncActor->id)->latest('id')->value('title'),'Queued worker reports completion in the staff inbox');
// A closed record gate still refreshes core facts, but cannot promise fresh records.
$syncInventory=$d->fresh()->observation['records'];$syncIo=[count($state->calls),count($observation->calls)];
$clock->instant=$clock->now()->modify('+61 seconds');
$syncJob->handle(new DomainObservation($readBindings,$clock,$budget,$ports,static fn()=>false));
$syncNotice=App\Models\Notification::where('user_id',$syncActor->id)->latest('id')->firstOrFail();
same('Records not read',$syncNotice->title,'Closed record gate is not reported as Domain synced');
same('Record checks are paused or not set up for this registrar account. Ask your site admin to check Processing and record-read setup, then press Sync now again.',$syncNotice->body,'Closed gate explains the missing record read and next step');
same($syncInventory,$d->fresh()->observation['records'],'Closed gate leaves record inventory and timestamp unchanged');
same($syncIo[0],count($state->calls),'Closed gate makes no record calls');
expect(count($observation->calls)>$syncIo[1],'Closed record gate still allows core facts to refresh');
same('refreshed',(new DomainObservation($readBindings,$clock,$budget))->run($d->id),'Core-only observers keep their existing result');
$syncActor->update(['role_id'=>null]);$syncIo=[count($state->calls),count($observation->calls)];$syncJob->handle($observer);
same($syncIo,[count($state->calls),count($observation->calls)],'Revoked staff job sends no registrar reads');
// An empty or wholly unknown refresh cannot release a primary-failure hold.
$beforeEmpty=$d->fresh()->observation;ObservationRecords::failPrimary($d->fresh(),$clock->now());
$failedEmpty=$d->fresh()->observation;
foreach ([[],['glue'=>['state'=>'partial','records'=>[]]]] as $unread) {
 ObservationRecords::save($d->fresh(),$unread,$clock->now());
 same($failedEmpty['record_hold'],$d->fresh()->observation['record_hold']??null,'Unread save retains the record hold');
 same($failedEmpty['record_failure'],$d->fresh()->observation['record_failure']??null,'Unread save retains primary-failure evidence');
}
$d->fresh()->update(['observation'=>$beforeEmpty]);
// Invalid child hosts cannot turn a filtered subset into a complete inventory.
try { ObservationRecords::save($d->fresh(),['glue'=>['state'=>'complete','records'=>['ns1.'.$d->domain=>'8.8.8.8','bad.'.$d->domain=>'10.0.0.1']]],$clock->now());expect(false,'Invalid glue must reject the snapshot'); }
catch (DomainException $error) { same('Invalid child-host read.',$error->getMessage(),'Discarded glue is not complete evidence'); }
same($beforeEmpty,$d->fresh()->observation,'Rejected child-host snapshot keeps previous evidence');
// A same-second inventory supersedes old preflight evidence, while a later preflight still wins.
$beforeSequence=$d->fresh()->observation;
$oldRows=[['name'=>'@','type'=>'TXT','address'=>'old preflight','mx_pref'=>10]];
\Paymenter\Extensions\Servers\Domains\Services\GlueRecords::prepared($d->fresh(),['dns'=>['records'=>$oldRows]],$clock->now());
$families=$beforeSequence['records']['families'];$families['dns']['records']=[['name'=>'@','type'=>'TXT','address'=>'new inventory','mx_pref'=>10]];$families['glue']['read_failed']=true;
ObservationRecords::save($d->fresh(),$families,$clock->now());
same('new inventory',OwnerRecords::forDomain($d->fresh())['dns'][0]['address'],'Same-second inventory supersedes old preflight');
$oldRows[0]['address']='new preflight';
\Paymenter\Extensions\Servers\Domains\Services\GlueRecords::prepared($d->fresh(),['dns'=>['records'=>$oldRows]],$clock->now());
same('new preflight',OwnerRecords::forDomain($d->fresh())['dns'][0]['address'],'Same-second later preflight remains current');
$d->fresh()->update(['observation'=>$beforeSequence]);
// Refresh replaces stale records, preserves complete empty vs unknown, stops at first failed wire.
$state->dns=[];$state->srv=[];$state->ds=[];$clock->instant=$clock->now()->modify('+61 seconds');
same('refreshed',$observer->run($d->id,readRecords:true),'Positive empty record containers are complete');
foreach(['dns','srv','ds']as$k){same([],OwnerRecords::forDomain($d->fresh())[$k],'Proven empty '.$k);}
$state->fault='GetDomainSRVHosts';$state->calls=[];
same('refreshed',$observer->run($d->id,readRecords:true),'Record failure preserves successful domain refresh');expect(ObservationRecords::held($d->fresh()),'Record stage is durably held');
same('read_failed',$d->fresh()->observation['record_hold']['code']??null,'Partial Enom discovery retains its safe failure cause');
require_once __DIR__.'/Support/staff-panel.php';
\Illuminate\Support\Facades\Auth::login(staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW]));
// Model the existing automatic retry separately from a permanent stop.
// Completion queued this exact holding already; model its first automatic retry in place.
$existing=\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::current($d)->sole();
DB::table('domains_observation_work')->where('id',$existing->id)->update(['due_at'=>$clock->now()->getTimestamp()+60,'safe_code'=>'read_failed','tries'=>1]);
same(null,\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::recordHold($d->fresh()),'Scheduled recovery has no stopped-read notice');
$partialJob=\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::current($d)->sole();
DB::table('domains_observation_work')->where('id',$partialJob->id)->update(['safe_code'=>'records_incomplete']);
same("Sync stopped: couldn't read SRV records. Other domain facts were saved. Use Sync now to try again.",\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::recordHold($d->fresh()),'Staff record notice names the stopped read');
$unsafe=clone $d->fresh();$unsafeFacts=$unsafe->observation;$unsafeFacts['record_hold']['code']='PRIVATE-PROVIDER-PAYLOAD';$unsafe->observation=$unsafeFacts;
same("Sync stopped: couldn't read SRV records. Other domain facts were saved. Use Sync now to try again.",\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::recordHold($unsafe),'Arbitrary stored payload cannot become a displayed cause');
\Illuminate\Support\Facades\Auth::login($user);
$r=$d->fresh()->observation['records'];same('complete',$r['families']['dns']['state'],'Independent completed family remains proven');
same('unknown',$r['families']['srv']['state'],'Failed family unknown');same('unknown',$r['families']['ds']['state'],'Unattempted family unknown');
expect(!in_array('GetDnsSec',array_column($state->calls,0),true),'No further wire after failure');
$html=$page();$srvDom=storefrontDom($html);
same('Missing',trim($srvDom->query('//li[@id="srv"]//*[@data-record-family="srv"]')->item(0)?->textContent??''),'Unknown stopped SRV shows Missing');
same(1,substr_count(html_entity_decode($html,ENT_QUOTES,'UTF-8'),\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::READ_HELD),'The customer reads the stopped-read notice once above the rows');
same(0,$srvDom->query('//li[@id="srv"]/span[@class="domains-row__v"]/span[@class="domains-sub"][contains(.,"complete supported record family")]')->length,'Unknown SRV does not duplicate the staff read cause');
same(0,storefrontDom($html)->query('//form[contains(@action,"/manage/srv")]')->length,'Failed refresh cannot retain old edit form');
expect(isset(OwnerRecords::forDomain($d->fresh())['dns']),'Healthy primary retains independently complete DNS under partial-record hold');
DB::table('domains_observation_work')->where('id',$partialJob->id)->update(['safe_code'=>$partialJob->safe_code]);
// A later primary failure also invalidates the healthy family under an existing partial-record hold.
$prior=$d->fresh()->observation;$observation->fault='info_missing';$clock->instant=$clock->now()->modify('+61 seconds');
same('read_no_evidence',$observer->run($d->id,readRecords:true),'Failed primary under existing partial-record hold');
same($prior['records'],$d->fresh()->observation['records'],'Existing hold cannot allow failed-primary inventory replacement');
same($prior['record_hold'],$d->fresh()->observation['record_hold'],'Existing record hold evidence is retained');
expect(!isset(OwnerRecords::forDomain($d->fresh())['dns']),'Failed primary blocks previously healthy DNS family under existing hold');
$observation->fault='';
$state->fault='';$clearRecords();$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id,readRecords:true);
// A changed provider ID/account/generation cannot reuse the saved inventory, even offline.
foreach(['provider_domain_id'=>'999','authority_generation'=>2,'account_fingerprint'=>str_repeat('f',64),'domain'=>'other-placeholder.com','registrar_key'=>'namesilo']as$field=>$value){
 $copy=clone $d->fresh();$copy->$field=$value;same(null,ObservationRecords::current($copy),'Reject saved stale '.$field);expect(!isset(OwnerRecords::forDomain($copy)['dns']),'Stale identity never unlocks DNS');
}
$prior=$d->fresh()->observation;$state->during=fn()=>DB::table('domains_domains')->where('id',$d->id)->update(['authority_generation'=>2]);
try{$observer->run($d->id,readRecords:true);expect(false,'Changed generation discarded');}catch(ReadDeferred){}
same($prior,$d->fresh()->observation,'In-flight stale generation applies no observations');
DB::table('domains_domains')->where('id',$d->id)->update(['authority_generation'=>1]);
// Provider ID changes between seed load and first lock must defer before a wire.
$race=true;DB::listen(function($q)use(&$race,$d){if($race&&str_contains($q->sql,'from "users"')){$race=false;DB::table('domains_domains')->where('id',$d->id)->update(['provider_domain_id'=>'999']);}});
$io=count($state->calls);try{$observer->run($d->id,readRecords:true);expect(false,'First-lock identity race must defer');}catch(ReadDeferred){}
same($io,count($state->calls),'Changed first-lock identity never reads the old provider ID');
DB::table('domains_domains')->where('id',$d->id)->update(['provider_domain_id'=>'456']);
// Fresh owned discovery requires the exact provider domain ID, never only a domain name.
DB::table('domains_domains')->where('id',$d->id)->update(['provider_domain_id'=>'999']);
same('refreshed',$observer->run($d->id,readRecords:true),'Foreign record provider ID preserves independent domain read');same('unknown',$d->fresh()->observation['records']['families']['dns']['state'],'Identity failure is unknown');
DB::table('domains_domains')->where('id',$d->id)->update(['provider_domain_id'=>'456']);$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id,readRecords:true);
// A successful ownership/drift read survives record rejection and quota deferral.
$observation->fault='owner';$state->calls=[];$clock->instant=$clock->now()->modify('+61 seconds');
same('domain_not_in_account',$observer->run($d->id,readRecords:true),'Proven ownership loss has a distinct stopped-read result');
same(false,$d->fresh()->observation['facts']['owned'],'Ownership loss persists despite unavailable records');expect($d->fresh()->observation['drift'],'Ownership drift retained');same([],$state->calls,'No private record discovery after ownership loss');
$observation->fault='';$clearRecords();$observer->run($d->id,readRecords:true);$priorInventory=$d->fresh()->observation['records'];$observation->years=2;
$limiter=new class implements \Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter {
 public int $reads=0;
 public function acquire(AccountBinding $b,RequestOrigin $o):string {if(++$this->reads>5){throw new ReadDeferred;}return 'fixture';}
 public function release(AccountBinding $b,RequestOrigin $o,string $token):void {}
};
same('read_deferred',(new DomainObservation($readBindings,$clock,$limiter,$ports))->run($d->id,readRecords:true),'Quota defers only record stage');
same('2028-10-18',$d->fresh()->observation['facts']['expiry'],'Successful domain facts saved on record deferral');same($priorInventory,$d->fresh()->observation['records'],'Deferred record stage leaves inventory untouched');
$observation->years=1;$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id,readRecords:true);
// An exact not-found read outranks older ownership facts until a strictly newer read of the same identity proves ownership.
$readJob=\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::current($d)->sole();
$staffViewer=staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW]);\Illuminate\Support\Facades\Auth::login($staffViewer);
$membership=static fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($d->fresh())['In registrar account']['value'];
same(true,$d->fresh()->observation['facts']['owned'],'Absence premise: saved facts prove ownership');same('Yes',$membership(),'Owned holding shows membership');
$observation->fault='absent';$clock->instant=$clock->now()->modify('+61 seconds');
same('domain_not_in_account',$observer->run($d->id,readRecords:true),'Exact Enom not-found read stops as absence');
DB::table('domains_observation_work')->where('id',$readJob->id)->update(['safe_code'=>'domain_not_in_account','tries'=>1,'claim'=>null,'lease_until'=>0]);
same(true,$d->fresh()->observation['facts']['owned'],'The failed read keeps the older ownership facts');
same('No',$membership(),'Membership follows the current not-found read, not older facts');
same('Domain is not in the registrar account.',\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::notice($d->fresh())['title'],'Staff see the absence notice');
expect(\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::absent($d->fresh()),'The not-found read is current absence');
foreach(['provider_domain_id'=>'999','authority_generation'=>2] as $field=>$value){$copy=clone $d->fresh();$copy->$field=$value;
    expect(!\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::absent($copy),'Absence for another '.$field.' does not apply');}
$equal=$d->fresh();$o=$equal->observation;$o['facts']['at']=$o['record_failure']['at'];$equal->observation=$o;
expect(\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::absent($equal),'Ownership facts from the same moment do not clear absence');
// A generic failure is not absence, so the saved facts stay as they were.
$observation->fault='transport';$clock->instant=$clock->now()->modify('+61 seconds');
same('read_failed',$observer->run($d->id,readRecords:true),'Transport failure stays a read failure');
expect(!\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::absent($d->fresh()),'A read failure is not absence');same('Yes',$membership(),'A read failure keeps saved membership');
$observation->fault='absent';$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id,readRecords:true);same('No',$membership(),'Absence is current again');
// A newer owned read with an incomplete record family clears absence and keeps the family diagnostic.
$observation->fault='';$state->fault='GetDomainSRVHosts';$clock->instant=$clock->now()->modify('+61 seconds');
same('refreshed',$observer->run($d->id,readRecords:true),'Newer owned read with an incomplete family');
same('domain_not_in_account',$d->fresh()->observation['record_failure']['code'],'Incomplete families keep the saved read failure');
expect(ObservationRecords::held($d->fresh()),'The incomplete family keeps its record hold');
same('Yes',$membership(),'Membership follows the newer ownership read');
same("Sync stopped: couldn't read SRV records. Other domain facts were saved. Use Sync now to try again.",\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::notice($d->fresh())['title'],'Staff see the family diagnostic instead of absence');
$status=\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::status($d->fresh());
same(['records_incomplete',true],[$status['read_code'],$status['read_stopped']],'The stopped job reports the incomplete family, not absence');
expect(!\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::absent($d->fresh()),'Newer ownership proof ends absence');
expect(ObservationProjection::recordStatus($d->fresh(),$clock->now())['families']['srv']['state']!=='complete','No complete family is invented');
expect(!isset(OwnerRecords::forDomain($d->fresh())['dns']),'Edits stay closed until a complete read');
// A complete read clears the failure.
$state->fault='';$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id,readRecords:true);
expect(!isset($d->fresh()->observation['record_failure']),'A complete read clears the saved failure');same('Yes',$membership(),'Recovered membership');
// Record checks off: a newer ownership-only read of the same identity answers membership, while the ownership hold stays for the next record read.
$observation->fault='owner';$clock->instant=$clock->now()->modify('+61 seconds');
same('domain_not_in_account',$observer->run($d->id,readRecords:true),'Ownership loss stops the read');
$drift=$d->fresh()->observation['record_hold'];same('ownership_drift',$drift['reason'],'Ownership loss holds the records');same('No',$membership(),'The ownership hold answers membership');
$observation->fault='';$state->calls=[];$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id,readRecords:false);
same([true,$clock->now()->format(DATE_ATOM)],[$d->fresh()->observation['facts']['owned'],$d->fresh()->observation['facts']['at']],'The ownership-only read saves newer ownership');
same([],$state->calls,'Record checks off read no records');same($drift,$d->fresh()->observation['record_hold'],'Record checks off keep the ownership hold');
same('Yes',$membership(),'A newer ownership-only read answers membership');
$state->fault='';$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id,readRecords:true);
expect(!ObservationRecords::held($d->fresh()),'A complete record read clears the ownership hold');same('Yes',$membership(),'Membership after the record read');
DB::table('domains_observation_work')->where('id',$readJob->id)->update(array_diff_key((array)$readJob,['id'=>true]));\Illuminate\Support\Facades\Auth::login($user);
$nativeSuccessful=true;echo "Initial inventory, sync jobs, sequences, partial failure and authority passed.\n";
