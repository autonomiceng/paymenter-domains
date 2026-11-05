<?php
// Enom 444f31c snapshot/glue cases; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationProjection,ObservationRecords,ObservationSchema,ObservationWork,OwnerRecords,ReadDeferred};
ObservationSchema::install();$budget=new AccountReadBudget($clock);$observer=new DomainObservation($readBindings,$clock,$budget,$ports);
[$d,$driver,$original]=$holding(placeholderComFixtureName('discovery-placeholder.com'));storefrontViews();
$clearRecords=static function()use(&$d):void{$fresh=$d->fresh();$p=$fresh->observation??[];unset($p['record_hold']);$fresh->update(['observation'=>$p]);};
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$before=$original->fresh()->getAttributes();$financial=[$d->provider_expires_at->format(DATE_ATOM),$d->confirmed_revision,$d->renewal_policy];
$beforeCount=Operation::count();$controller->manage($post('dns',['records'=>[['name'=>'@','type'=>'A','address'=>'8.8.8.8']],'preimage_digest'=>str_repeat('a',64)]),$d->id,'dns');
same($beforeCount,Operation::count(),'Forged DNS POST cannot bypass initial completeness gate');
$state->dns=[['name'=>'@','type'=>'TXT','address'=>' exact  TXT ','mx_pref'=>10]];
$state->srv=[['service'=>'sip','protocol'=>'tcp','priority'=>0,'weight'=>5,'port'=>443,'target'=>'sip.example.com.']];
$state->ds=[['algorithm'=>13,'digest_type'=>2,'key_tag'=>12,'digest'=>str_repeat('A',64)]];
same('refreshed',$observer->run($d->id,readRecords:true),'Initial record discovery succeeds through real parsers');
$r=$d->fresh()->observation['records'];same(ObservationRecords::identity($d),$r['identity'],'Inventory binds every current identity');
foreach(['dns','srv','ds']as$k){same('complete',$r['families'][$k]['state'],'Explicit complete '.$k);}
same('partial',$r['families']['glue']['state'],'No complete glue enumeration claim');
expect(!str_contains(DB::table('domains_domains')->where('id',$d->id)->value('observation'),'exact  TXT'),'Inventory encrypted at rest');
same(' exact  TXT ',OwnerRecords::forDomain($d->fresh())['dns'][0]['address'],'Exact TXT bytes reach safe DTO');
$io=count($state->calls)+count($observation->calls);$html=$page();
expect(str_contains($html,'/manage/dns')&&str_contains($html,'/manage/srv'),'Complete initial inventory unlocks forms');
same($io,count($state->calls)+count($observation->calls),'GET has no provider transport');
same($before,$original->fresh()->getAttributes(),'Acquisition/financial proof remains byte-identical');
same($financial,[$d->fresh()->provider_expires_at->format(DATE_ATOM),$d->fresh()->confirmed_revision,$d->fresh()->renewal_policy],'Confirmed expiry/revision/anchor retained');
$staleIdentity=ObservationRecords::identity($d);$staleIdentity['generation']=2;$count=Operation::count();
try{$management->request($user,$d->id,'dns',[],hash('sha256','stale-owner-records'),$driver,recordIdentity:$staleIdentity);expect(false,'Locked owner record identity rejects stale admission');}catch(DomainException){}
same($count,Operation::count(),'No owner operation created with stale inventory identity');
require __DIR__.'/Support/observation-failed-primary.php';
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
same("Sync stopped: couldn't read DNS records.",\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::recordHold($d->fresh()),'Staff record notice names the stopped read');
$unsafe=clone $d->fresh();$unsafeFacts=$unsafe->observation;$unsafeFacts['record_hold']['code']='PRIVATE-PROVIDER-PAYLOAD';$unsafe->observation=$unsafeFacts;
same("Sync stopped: couldn't read DNS records.",\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::recordHold($unsafe),'Arbitrary stored payload cannot become a displayed cause');
\Illuminate\Support\Facades\Auth::login($user);
$r=$d->fresh()->observation['records'];same('complete',$r['families']['dns']['state'],'Independent completed family remains proven');
same('unknown',$r['families']['srv']['state'],'Failed family unknown');same('unknown',$r['families']['ds']['state'],'Unattempted family unknown');
expect(!in_array('GetDnsSec',array_column($state->calls,0),true),'No further wire after failure');
$html=$page();same(['Missing','Our team needs to check this domain. No action needed.'],array_map(fn($n)=>trim($n->textContent),iterator_to_array(storefrontDom($html)->query('//li[@id="srv"]/span[@class="domains-row__v"]//span[contains(concat(@class," "),"domains-missing ") or @class="domains-sub"]'))),'Unknown SRV held shows Missing with its cause');
same(0,storefrontDom($html)->query('//form[contains(@action,"/manage/srv")]')->length,'Failed refresh cannot retain old edit form');
expect(isset(OwnerRecords::forDomain($d->fresh())['dns']),'Healthy primary retains independently complete DNS under partial-record hold');
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
same('unresolved',$observer->run($d->id,readRecords:true),'Ownership loss retains unresolved drift status');
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
// Same-clock management readbacks overlay only after the snapshot sequence watermark.
Cache::flush();$op=$submit($d,$driver,'dns',['records'=>[['name'=>'www','type'=>'A','address'=>'8.8.8.8']],'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest([])]);
same('succeeded',$management->run($op->id,$driver)->state,'Separate authorized record write');
same('www',OwnerRecords::forDomain($d->fresh())['dns'][0]['name'],'Later sequence overlays same-clock inventory');
$state->dns=[];$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id,readRecords:true);same([],OwnerRecords::forDomain($d->fresh())['dns'],'New snapshot supersedes older operation history');
Cache::flush();$op=$submit($d,$driver,'lock',['locked'=>true]);same('succeeded',$management->run($op->id,$driver)->state,'Unrelated section readback');
$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id,readRecords:true);expect(isset(ObservationProjection::sections($d->fresh())['lock']),'Record watermark retains unrelated proven section facts');
// Partial known glue refresh, changed and absent hosts, no adoption or complete enumeration.
Cache::flush();$op=$submit($d,$driver,'glue_add',['host'=>'ns1.discovery-placeholder.com','ip'=>'8.8.8.8']);same('succeeded',$management->run($op->id,$driver)->state,'Known local child host');
$state->glue['ns1.discovery-placeholder.com']='1.1.1.1';$state->calls=[];$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id,readRecords:true);
same('1.1.1.1',OwnerRecords::forDomain($d->fresh())['glue'][0]['ip'],'Known host refreshed through CheckNSStatus');
expect(in_array('CheckNSStatus',array_column($state->calls,0),true),'Only explicit known child queried');
$state->glue['ns1.discovery-placeholder.com']='10.0.0.1';$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id,readRecords:true);
same('1.1.1.1',OwnerRecords::forDomain($d->fresh())['glue'][0]['ip'],'Non-public read preserves confirmed host without absence proof');expect(ObservationRecords::held($d->fresh()),'Invalid child address holds record stage');
$io=count($state->calls);$clock->instant=$clock->now()->modify('+1 day');$observer->run($d->id,readRecords:false);same($io,count($state->calls),'Invalid child read does not retry automatically');$state->glue['ns1.discovery-placeholder.com']='1.1.1.1';
foreach(['GetHosts','GetDomainInfo','CheckNSStatus']as$fault){
 $clearRecords();$state->fault=$fault;$clock->instant=$clock->now()->modify('+61 seconds');
 same('refreshed',$observer->run($d->id,readRecords:true),'Failed record read holds only records: '.$fault);
 same('1.1.1.1',OwnerRecords::forDomain($d->fresh())['glue'][0]['ip'],'Failed read retains confirmed partial child history');
 if($fault==='CheckNSStatus') {
  same('glue_unavailable',$d->fresh()->observation['record_hold']['reason'],'Legacy Enom glue-only failure retains its specific hold reason');
  same('glue',$d->fresh()->observation['record_hold']['family'],'Legacy Enom glue-only failure identifies the failed family');
  same($clock->now()->format(DATE_ATOM),$d->fresh()->last_synced_at->format(DATE_ATOM),'Glue parser failure still saves fresh core facts');
  foreach(['dns','srv','ds'] as $kind) { same('complete',$d->fresh()->observation['records']['families'][$kind]['state'],'Glue parser failure preserves completed '.$kind); }
  \Illuminate\Support\Facades\Auth::login(staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW]));
  same("Couldn't read glue records. Other domain facts were saved. Use Sync now to try again.",\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::recordHold($d->fresh()),'Glue failure is not presented as a stopped whole-domain sync');
  \Illuminate\Support\Facades\Auth::login($user);
 }
}
$state->fault='';$clearRecords();
$state->glue=[];$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id,readRecords:true);same([],OwnerRecords::forDomain($d->fresh())['glue'],'Exact missing-host proof removes local history');
// Driver refresh is bounded and retains known hosts beyond its per-run child read cap.
$known=[];foreach(range(1,14)as$i){$known['ns'.$i.'.discovery-placeholder.com']='8.8.8.8';}$state->glue=$known;$state->calls=[];
$b=$driver->capabilities()->binding;$ctx=new RequestContext($b,RequestOrigin::Background,$clock->now()->modify('+250 seconds'),new RequestBudget(25),$clock);
$partial=$driver->discover(new ManagementRequest($b,'bounded-glue','discovery-placeholder.com','discovery',[],'456'),$ctx,$known);
same(13,count(array_filter($state->calls,fn($r)=>$r[0]==='CheckNSStatus')),'At most13 known child hosts refreshed');
same(14,count($partial['glue']['records']),'Unchecked hosts retained explicitly partial');
$state->calls=[];$ctx=new RequestContext($b,RequestOrigin::Background,$clock->now()->modify('+250 seconds'),new RequestBudget(25),$clock);$driver->discover(new ManagementRequest($b,'rotated-glue','discovery-placeholder.com','discovery',[],'456'),$ctx,$partial['glue']['records']);
expect(in_array(['CheckNSStatus',['CheckNSName'=>'ns14.discovery-placeholder.com']],$state->calls,true),'Next turn rotates to previously unchecked child host');
// Scheduled core reads never discover records, including after a record hold or resume.
$work=new ObservationWork($observer,$clock,$budget);$work->enroll($d->server_id);
$before=$d->fresh()->observation['records'];$io=count($state->calls);$domainIo=count($observation->calls);
$clock->instant=$clock->now()->modify('+1 day');$work->tick(1);
same($io,count($state->calls),'Daily work makes no glue, DNS, SRV or DS registrar reads');
same($before,$d->fresh()->observation['records'],'Daily work preserves record timestamp and inventory');
expect(in_array(\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Nameservers,array_slice($observation->calls,$domainIo),true),'Daily primary read still checks nameservers');
$job=DB::table('domains_observation_work')->where('domain_id',$d->id)->sole();
// An explicit sync can retry a failed record read, without a new staff recovery step.
$state->fault='GetHosts';$observer->run($d->id,readRecords:true);expect(ObservationRecords::held($d->fresh()),'Failed explicit record read is visible');
$state->fault='';$observer->run($d->id,readRecords:true);expect(!ObservationRecords::held($d->fresh()),'Next explicit sync recovers record read');
// Enom discovers an in-domain nameserver it has never managed locally; templates seed hosts too.
$observation->nameservers=$state->ns=['ns-fresh.'.$d->domain,'ns2.example.net'];$state->glue['ns-fresh.'.$d->domain]='8.8.4.4';
$state->calls=[];$observer->run($d->id,readRecords:true);
expect(in_array(['CheckNSStatus',['CheckNSName'=>'ns-fresh.'.$d->domain]],$state->calls,true),'Current nameservers seed unknown child hosts');
\Paymenter\Extensions\Servers\Domains\Services\WatchDnsSchema::install();
DB::table('domains_watch_profiles')->insert(['name'=>'Glue candidate fixture','nameservers'=>json_encode(['ns-template.'.$d->domain,'ns.other.example.net']),'website_endpoints'=>'{}','email_endpoints'=>'{}','edited_by'=>$user->id,'reason'=>'synthetic']);
$state->glue['ns-template.'.$d->domain]='8.8.4.4';$state->calls=[];$observer->run($d->id,readRecords:true);
expect(in_array(['CheckNSStatus',['CheckNSName'=>'ns-template.'.$d->domain]],$state->calls,true),'Template child hosts discovered without changing the template');
expect(!in_array(['CheckNSStatus',['CheckNSName'=>'ns.other.example.net']],$state->calls,true),'Unrelated template hosts excluded');
// The independent editor/no-lock checks require confirmed registrar DNS after custom glue discovery.
$observation->nameservers=$state->ns=['dns1.name-services.com','dns2.name-services.com'];
$clock->instant=$clock->now()->modify('+61 seconds');
same('refreshed',$observer->run($d->id,readRecords:true),'Registrar DNS delegation restores the record editor fixture');
// GET presentation has no writes; query bounds cap operation history decryption.
DB::connection()->enableQueryLog();$page();$queries=DB::getQueryLog();DB::connection()->disableQueryLog();
foreach($queries as$q){expect(!preg_match('/^(?:insert|update|delete) /i',$q['query']),'Owner GET has no database writes');if(str_contains($q['query'],'from "domains_operations"')){expect(str_contains($q['query'],'limit'),'Owner operation scans are bounded');}}
// SQLite normally drops FOR UPDATE. Reject lock compilation to verify advisory behavior explicitly.
$conn=DB::connection();$grammar=$conn->getQueryGrammar();$strict=new class($conn) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
 public int $locks=0;
 protected function compileLock(\Illuminate\Database\Query\Builder $q,$value){$this->locks++;throw new RuntimeException('Unexpected row lock');}
};
$conn->setQueryGrammar($strict);
try {
 $html=$page();same(1,storefrontDom($html)->query('//form[contains(@action,"/manage/dns")]')->length,'Advisory form remains available without locking reads');same(0,$strict->locks,'GET never attempts a row lock');
 try{DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($d));expect(false,'Write prefix must lock');}catch(RuntimeException $e){same('Unexpected row lock',$e->getMessage(),'Default mutation prefix retains locks');}
 same(1,$strict->locks,'Mutation locking remains the default');
}finally{$conn->setQueryGrammar($grammar);}
// A confirmed51st host must not be truncated by publication or by a failed domain read.
$known50=[];foreach(range(1,50)as$i){$known50['ns'.$i.'.discovery-placeholder.com']='8.8.8.8';}
$f=$d->fresh()->observation['records']['families'];$f['glue']=['state'=>'partial','records'=>$known50];ObservationRecords::save($d->fresh(),$f,$clock->now());$state->glue=$known50;
Cache::flush();$op=$submit($d,$driver,'glue_add',['host'=>'ns51.discovery-placeholder.com','ip'=>'8.8.4.4']);same('succeeded',$management->run($op->id,$driver)->state,'Confirmed51st child host');
$prior=$d->fresh()->observation['records'];$clock->instant=$clock->now()->modify('+61 seconds');
same('refreshed',$observer->run($d->id,readRecords:true),'Host overflow holds only record publication');same($prior,$d->fresh()->observation['records'],'Host overflow cannot advance watermark');
same(51,count(ObservationProjection::sections($d->fresh())['known_glue']),'All51 confirmed hosts remain recoverable');expect(!isset(OwnerRecords::forDomain($d->fresh())['dns']),'Host overflow holds owner whole-list forms');
$observation->fault='info_missing';same('read_no_evidence',$observer->run($d->id,readRecords:true),'Failed domain read still preserves overflowed history');same($prior,$d->fresh()->observation['records'],'Failure saving never truncates confirmed51st host');$observation->fault='';
// History overflow never publishes a snapshot that forgets later confirmed child history.
Cache::flush();$op=$submit($d,$driver,'glue_add',['host'=>'ns102.discovery-placeholder.com','ip'=>'8.8.4.4']);$management->run($op->id,$driver);
$row=$op->fresh()->getRawOriginal();unset($row['id']);
for($i=0;$i<101;$i++){$row['operation_key']=hash('sha256','overflow-'.$i);DB::table('domains_operations')->insert($row);}
$prior=$d->fresh()->observation['records'];$io=count($state->calls);$observation->years=2;
DB::table('domains_observation_work')->where('id',$job->id)->update(['due_at'=>$clock->now()->getTimestamp(),'safe_code'=>null]);
$observer->run($d->id,readRecords:true);
same('unresolved',$work->tick(2)[$d->server_id],'Overflow holds records while domain work continues and reports drift');
same($prior,$d->fresh()->observation['records'],'Overflow does not advance inventory or its watermark');same($io,count($state->calls),'Overflow skips record provider reads');
same('2028-10-18',$d->fresh()->observation['facts']['expiry'],'Overflow still refreshes domain drift facts');expect(!isset(OwnerRecords::forDomain($d->fresh())['dns']),'Overflow does not present stale whole-list records');
$domainIo=count($observation->calls);$observation->years=3;$clock->instant=$clock->now()->modify('+1 day');$work->tick(2);expect(count($observation->calls)>$domainIo,'Overflow retains daily domain refresh');same($io,count($state->calls),'Overflow never retries record discovery automatically');same('2029-10-18',$d->fresh()->observation['facts']['expiry'],'Overflow cannot freeze daily expiry facts');
// History alone can overflow while partial child-host history stays below its separate cap.
$observation->years=1;
[$d,$driver]=$holding('history-placeholder.com');
$state->dns=[['name'=>'@','type'=>'TXT','address'=>'old','mx_pref'=>10]];
$state->srv=[['service'=>'sip','protocol'=>'tcp','priority'=>0,'weight'=>5,'port'=>443,'target'=>'sip.example.com.']];
$state->ds=[['algorithm'=>13,'digest_type'=>2,'key_tag'=>12,'digest'=>str_repeat('A',64)]];
same('refreshed',$observer->run($d->id,readRecords:true),'History fixture starts with complete inventory');
$families=$d->fresh()->observation['records']['families'];
$families['glue']=['state'=>'partial','records'=>['ns1.history-placeholder.com'=>'8.8.8.8']];
ObservationRecords::save($d->fresh(),$families,$clock->now());
foreach ([
    ['dns',['records'=>[['name'=>'@','type'=>'TXT','address'=>'new','mx_pref'=>10]],'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest($state->dns)]],
    ['srv',['records'=>[['service'=>'sip','protocol'=>'tcp','priority'=>0,'weight'=>5,'port'=>8443,'target'=>'sip.example.com.']],'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest($state->srv,'srv')]],
    ['ds_add',['ds'=>['algorithm'=>13,'digest_type'=>2,'key_tag'=>13,'digest'=>str_repeat('B',64)]]],
    ['nameservers',['nameservers'=>['ns3.example.net','ns4.example.net']]],
    ['lock',['locked'=>false]],
] as [$action,$payload]) {
    Cache::flush();$changed=$submit($d,$driver,$action,$payload);
    same('succeeded',$management->run($changed->id,$driver)->state,'Newer confirmed history '.$action);
}
$state->privacy=true;$state->enabled=true;Cache::flush();$changed=$submit($d,$driver,'privacy_disable');
same('succeeded',$management->run($changed->id,$driver)->state,'History fixture has a real privacy readback');
$preserved=$d->fresh()->observation['sections'];
$raw=$changed->fresh()->getRawOriginal();unset($raw['id']);
// Unsent rejected history is resolved without borrowing another operation's readback.
$raw['state']='rejected';$raw['attempt']=null;
for ($i=$d->operations()->count();$i<100;$i++) {
    $raw['operation_key']=hash('sha256','history-bound-'.$i);DB::table('domains_operations')->insert($raw);
}
same(100,$d->operations()->count(),'Exact reconstruction boundary');
$known=OwnerRecords::forDomain($d->fresh());
same('new',$known['dns'][0]['address'],'At100 newer DNS replaces inventory');
same(8443,$known['srv'][0]['port'],'At100 newer SRV replaces inventory');
same(2,count($known['ds']),'At100 newer DS overlays inventory');
$srvPayload=['records'=>$known['srv'],'record_sources'=>['0'],'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest($known['srv'],'srv')];
same($known['srv'],OwnerRecords::restoreSrv($d->fresh(),$srvPayload)['records'],'At100 SRV source indexes remain usable');
$raw['operation_key']=hash('sha256','history-bound-101');DB::table('domains_operations')->insert($raw);
same(101,$d->operations()->count(),'History overflow is independent of the glue cap');
$inventory=$d->fresh()->observation['records'];$domainIo=count($observation->calls);$recordIo=count($state->calls);
foreach ([1,2] as $cycle) {
    $sections=ObservationProjection::sections($d->fresh());
    foreach (['dns','srv','ds'] as $kind) {
        expect(!isset($sections[$kind]),'History overflow omits stale '.$kind.' projection');
        expect(!isset(OwnerRecords::forDomain($d->fresh())[$kind]),'History overflow hides owner '.$kind.' list');
    }
    try { OwnerRecords::restoreSrv($d->fresh(),$srvPayload);expect(false,'Overflow must refuse stale SRV sources'); }
    catch (\Paymenter\Extensions\Servers\Domains\Services\OwnerFormValidation) {}
    foreach (['nameservers','lock','privacy'] as $kind) { same($preserved[$kind],$sections[$kind],'Overflow keeps matched '.$kind); }
    same(['ns1.history-placeholder.com'=>'8.8.8.8'],$sections['known_glue'],'Overflow retains explicitly partial glue');
    foreach (['name'=>'other.example.com','generation'=>2,'account_fingerprint'=>str_repeat('f',64),'legacy'=>null] as $field=>$value) {
        $copy=clone $d->fresh();$saved=$copy->observation;
        foreach (['nameservers','lock','privacy'] as $kind) {
            if ($field==='legacy') { unset($saved['sections'][$kind]['name'],$saved['sections'][$kind]['generation'],$saved['sections'][$kind]['account_fingerprint']); }
            else { $saved['sections'][$kind][$field]=$value; }
        }
        $copy->observation=$saved;$rejected=ObservationProjection::sections($copy);
        same([],$rejected['nameservers'],'Overflow refuses '.$field.' nameservers');
        foreach (['lock','privacy'] as $kind) { expect(!isset($rejected[$kind]),'Overflow refuses '.$field.' '.$kind); }
    }
    $clock->instant=$clock->now()->modify('+1 day');
    same('refreshed',$observer->run($d->id,readRecords:false),'Core-only sync still refreshes facts during overflow cycle '.$cycle);
    same($inventory,$d->fresh()->observation['records'],'Overflow cannot advance inventory cycle '.$cycle);
    expect(!ObservationRecords::held($d->fresh()),'Core-only sync does not manufacture a history hold');
    $preserved=$d->fresh()->observation['sections'];
    same($observation->nameservers,$preserved['nameservers']['facts']['nameservers'],'Daily read refreshes current nameservers during overflow');
}
same($recordIo,count($state->calls),'Core-only reads do not discover records during history overflow');
expect(count($observation->calls)>$domainIo,'Daily domain reads continue across overflow cycles');

// Overflow keeps fresh per-host evidence, and later confirmed deletion wins over inventory/preflight.
$directHost='ns-direct.'.$d->domain;$overflowHost='ns-new.'.$d->domain;$inventoryHost='ns1.'.$d->domain;
$state->glue[$directHost]='1.1.1.1';
\Paymenter\Extensions\Servers\Domains\Services\GlueRecords::prepared($d->fresh(),['glue'=>['host'=>$directHost,'ip'=>'1.1.1.1']],$clock->now());
$observed=['fresh direct read'=>ObservationProjection::sections($d->fresh())['known_glue'][$directHost]??null];
require_once __DIR__.'/Support/FakeDnsReader.php';
$dns=new \Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader();
$query=new \Paymenter\Extensions\Servers\Domains\Dns\DnsQuery($directHost,'A');
$dns->answers[$query->key()]=\Paymenter\Extensions\Servers\Domains\Dns\DnsAnswer::negative($query);
$drift=\Paymenter\Extensions\Servers\Domains\Services\GlueDrift::check($d->fresh(),$dns,new \Paymenter\Extensions\Servers\Domains\Dns\DnsContext(),0,$clock->now()->getTimestamp(),900);
$observed['fresh host drift check']=$drift['hosts'][$directHost]['drift']??null;
foreach (['glue_add'=>['host'=>$overflowHost,'ip'=>'1.1.1.1'],'glue_delete'=>['host'=>$overflowHost]] as $action=>$payload) {
    Cache::flush();$changed=$submit($d,$driver,$action,$payload);
    same('succeeded',$management->run($changed->id,$driver)->state,'Overflow child-host '.$action.' confirmed');
    $observed[$action]=ObservationProjection::sections($d->fresh())['known_glue'][$overflowHost]??null;
}
$state->glue[$inventoryHost]='8.8.8.8';
Cache::flush();$changed=$submit($d,$driver,'glue_delete',['host'=>$inventoryHost]);
same('succeeded',$management->run($changed->id,$driver)->state,'Overflow inventory-host delete confirmed');
$observed['inventory host delete']=ObservationProjection::sections($d->fresh())['known_glue'][$inventoryHost]??null;
// Readbacks must remain current after the operation itself falls out of the bounded window.
for ($i=0;$i<101;$i++) { $raw['operation_key']=hash('sha256','history-after-delete-'.$i);DB::table('domains_operations')->insert($raw); }
$truncated=ObservationProjection::sections($d->fresh());
$observed['aged new host delete']=$truncated['known_glue'][$overflowHost]??null;
$observed['aged inventory host delete']=$truncated['known_glue'][$inventoryHost]??null;
$expected=['fresh direct read'=>'1.1.1.1','fresh host drift check'=>true,'glue_add'=>'1.1.1.1','glue_delete'=>null,
    'inventory host delete'=>null,'aged new host delete'=>null,'aged inventory host delete'=>null];
foreach ($expected as $case=>$value) { if ($value!==$observed[$case]) { fwrite(STDERR,'Regression: '.$case.' expected '.json_encode($value).' got '.json_encode($observed[$case])."\n"); } }
same('1.1.1.1',$observed['fresh direct read'],'Fresh persisted glue survives truncated history');
same($expected,$observed,'Fresh direct/add evidence stays visible and drift-checked; newer deletes never resurrect after overflow');
foreach (['dns','srv','ds'] as $kind) { expect(!isset($truncated[$kind]),'Deleted glue does not unlock truncated '.$kind.' list'); }


// A fresh read safely replaces stale whole-list inventory despite the lifetime operation count.
$recordIo=count($state->calls);$clock->instant=$clock->now()->modify('+61 seconds');
ObservationRecords::hold($d->fresh(),'history_limit',$clock->now());
same('refreshed',$observer->run($d->id,readRecords:true),'Sync recovers a legacy history-limit hold');
expect(count($state->calls)>$recordIo,'More than100 operations no longer refuse record discovery');
expect(!ObservationRecords::held($d->fresh()),'Successful discovery clears the old history hold');
$freshRecords=OwnerRecords::forDomain($d->fresh());
same('new',$freshRecords['dns'][0]['address'],'Fresh inventory is visible after overflow');
same(8443,$freshRecords['srv'][0]['port'],'Fresh SRV inventory is visible after overflow');
same(2,count($freshRecords['ds']),'Fresh DS inventory is visible after overflow');
same($clock->now()->format(DATE_ATOM),$d->fresh()->observation['records']['at'],'Fresh inventory advances its timestamp');
same([$directHost=>'1.1.1.1'],array_column($freshRecords['glue'],'ip','host'),'Fresh discovery keeps the live direct host and cannot resurrect either delete');


// The combined child-host limit is a specific hold; reducing its sources makes retry work.
[$d,$driver]=$holding('candidate-limit-placeholder.com');
// The registration fixture rewinds the clock; isolate this case from earlier quota windows.
DB::table('domains_read_budgets')->where('server_id',$d->server_id)->delete();
$profiles=[];
for ($i=0;$i<26;$i++) {
    $profiles[]=DB::table('domains_watch_profiles')->insertGetId(['name'=>'Limit fixture '.$i,
        'nameservers'=>json_encode(['ns'.($i*2+1).'.'.$d->domain,'ns'.($i*2+2).'.'.$d->domain]),
        'website_endpoints'=>'{}','email_endpoints'=>'{}','edited_by'=>$user->id,'reason'=>'synthetic']);
}
$limitActor=staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,'admin.domains.manage']);
$limitJob=new \Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords($limitActor->id,$d->id,$d->authority_generation,$d->account_fingerprint);
$recordIo=count($state->calls);$clock->instant=$clock->now()->modify('+61 seconds');$limitJob->handle($observer);
same($recordIo,count($state->calls),'Candidate limit makes no record-discovery calls');
expect(isset($d->fresh()->observation['facts']),'Candidate limit still saves core facts');
same('child_host_limit',$d->fresh()->observation['record_hold']['reason'],'Candidate limit has its own recoverable cause');
$notice=App\Models\Notification::where('user_id',$limitActor->id)->latest('id')->firstOrFail();
same("Couldn't read all records",$notice->title,'Candidate limit never reports Domain synced');
same("Missing: more than 50 child hosts to check. Ask your site admin to remove unused entries from this domain's saved discovery data, registrar nameservers and DNS templates so at most 50 child hosts remain, then use Sync now.",$notice->body,'Candidate-limit inbox gives the real limit and required source cleanup');
DB::table('domains_watch_profiles')->where('id',array_pop($profiles))->delete();
$clock->instant=$clock->now()->modify('+61 seconds');$limitJob->handle($observer);
expect(!ObservationRecords::held($d->fresh()),'Reducing candidates to50 allows successful discovery ('.($d->fresh()->observation['record_hold']['reason']??'none').'/'.($d->fresh()->observation['record_hold']['code']??'none').')');
same(50,count($d->fresh()->observation['records']['families']['glue']['candidates']),'Discovery retains every candidate at its limit');
same('Domain synced',App\Models\Notification::where('user_id',$limitActor->id)->latest('id')->value('title'),'Retry after reducing sources honestly reports success');

// NS15 uses the native encrypted model and existing synthetic provider transport: a plain codec cannot prove owner authority.
[$d,$driver,$original]=$holding(placeholderComFixtureName('ns15-enom-placeholder.com'));
$b=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$d->server_id)->sole());
$ns15=new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\ObservationReader($b,$exchange);
$ctx=fn()=>new RequestContext($b,RequestOrigin::Background,$clock->now()->modify('+250 seconds'),new RequestBudget(25),$clock,stopOnReadFailure:true);
$request=new ManagementRequest($b,'ns15-enom',$d->domain,'discovery',[],$d->provider_domain_id,domainId:$d->id);
$state->contacts=['registrant'=>['FirstName'=>'Synthetic']];
$discovered=$ns15->discover($request,$ctx());
same('partial',$discovered['settings']['contacts']['state'],'Missing Enom contact roles are partial');
ObservationRecords::save($d,$discovered,$clock->now(),$ns15->observationCapabilities());
$r=$d->fresh()->observation['records'];same(1,$r['format'],'Bounded refresh emits versioned records');
same('partial',$r['settings']['contacts']['state'],'Encrypted partial contacts survive');
$view=ObservationProjection::recordStatus($d->fresh(),$clock->now());
same('complete',$view['families']['dns']['state'],'Supported complete empty remains complete');
same('partial',$view['settings']['contacts']['state'],'Staff can identify failed contact family');
$state->contacts=array_fill_keys(['registrant','admin','tech','auxbilling'],['FirstName'=>'Synthetic','LastName'=>'Person','Address1'=>'1 Example Street','City'=>'Portland','PostalCode'=>'97201','Country'=>'US','EmailAddress'=>'synthetic@example.com','Phone'=>'+1.5035550123']);
$discovered=$ns15->discover($request,$ctx());
ObservationRecords::save($d->fresh(),$discovered,$clock->now(),$ns15->observationCapabilities());
same(false,ObservationRecords::incomplete($d->fresh()),'Successful bounded partial Enom glue is sufficient');
$good=$d->fresh()->observation['records'];
$state->fault='GetDomainSRVHosts';$state->calls=[];$clock->instant=$clock->now()->modify('+1 minute');
$partial=$ns15->discover($request,$ctx());
ObservationRecords::save($d->fresh(),$partial,$clock->now(),$ns15->observationCapabilities());
expect(ObservationRecords::incomplete($d->fresh()),'Failed expected Enom family needs resume');
same('complete',$d->fresh()->observation['records']['families']['dns']['state'],'Independent DNS remains complete');
same($good['at'],ObservationProjection::recordStatus($d->fresh(),$clock->now())['families']['srv']['last_good_at'],'Failed family retains last-good time');
expect(!in_array('GetDnsSec',array_column($state->calls,0),true),'Reader stops after first failed wire');
$state->fault='';
$future=$d->fresh()->observation;$future['records']['at']=$clock->now()->modify('+1 hour')->format(DATE_ATOM);$d->update(['observation'=>$future]);
same(null,ObservationRecords::current($d->fresh(),$clock->now()),'Future inventory grants no current authority');

$nativeSuccessful=true;echo "Initial managed records: complete/unknown, identity, encryption, sequence, owner gates, glue and fair held work passed.\n";
