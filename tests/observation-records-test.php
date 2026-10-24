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
same('refreshed',$observer->run($d->id),'Initial record discovery succeeds through real parsers');
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
// Refresh replaces stale records, preserves complete empty vs unknown, stops at first failed wire.
$state->dns=[];$state->srv=[];$state->ds=[];$clock->instant=$clock->now()->modify('+61 seconds');
same('refreshed',$observer->run($d->id),'Positive empty record containers are complete');
foreach(['dns','srv','ds']as$k){same([],OwnerRecords::forDomain($d->fresh())[$k],'Proven empty '.$k);}
$state->fault='GetDomainSRVHosts';$state->calls=[];
same('refreshed',$observer->run($d->id),'Record failure preserves successful domain refresh');expect(ObservationRecords::held($d->fresh()),'Record stage is durably held');
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
$html=$page();same(['Missing','Shows when our team resumes record checks'],array_map(fn($n)=>trim($n->textContent),iterator_to_array(storefrontDom($html)->query('//li[@id="srv"]/span[@class="domains-row__v"]/span[@class="domains-missing" or @class="domains-sub"]'))),'Unknown SRV held shows Missing with its cause');
same(0,storefrontDom($html)->query('//form[contains(@action,"/manage/srv")]')->length,'Failed refresh cannot retain old edit form');
expect(isset(OwnerRecords::forDomain($d->fresh())['dns']),'Healthy primary retains independently complete DNS under partial-record hold');
// A later primary failure also invalidates the healthy family under an existing partial-record hold.
$prior=$d->fresh()->observation;$observation->fault='info_missing';$clock->instant=$clock->now()->modify('+61 seconds');
same('read_no_evidence',$observer->run($d->id),'Failed primary under existing partial-record hold');
same($prior['records'],$d->fresh()->observation['records'],'Existing hold cannot allow failed-primary inventory replacement');
same($prior['record_hold'],$d->fresh()->observation['record_hold'],'Existing record hold evidence is retained');
expect(!isset(OwnerRecords::forDomain($d->fresh())['dns']),'Failed primary blocks previously healthy DNS family under existing hold');
$observation->fault='';
$state->fault='';$clearRecords();$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id);
// A changed provider ID/account/generation cannot reuse the saved inventory, even offline.
foreach(['provider_domain_id'=>'999','authority_generation'=>2,'account_fingerprint'=>str_repeat('f',64),'domain'=>'other-placeholder.com','registrar_key'=>'namesilo']as$field=>$value){
 $copy=clone $d->fresh();$copy->$field=$value;same(null,ObservationRecords::current($copy),'Reject saved stale '.$field);expect(!isset(OwnerRecords::forDomain($copy)['dns']),'Stale identity never unlocks DNS');
}
$prior=$d->fresh()->observation;$state->during=fn()=>DB::table('domains_domains')->where('id',$d->id)->update(['authority_generation'=>2]);
try{$observer->run($d->id);expect(false,'Changed generation discarded');}catch(ReadDeferred){}
same($prior,$d->fresh()->observation,'In-flight stale generation applies no observations');
DB::table('domains_domains')->where('id',$d->id)->update(['authority_generation'=>1]);
// Provider ID changes between seed load and first lock must defer before a wire.
$race=true;DB::listen(function($q)use(&$race,$d){if($race&&str_contains($q->sql,'from "users"')){$race=false;DB::table('domains_domains')->where('id',$d->id)->update(['provider_domain_id'=>'999']);}});
$io=count($state->calls);try{$observer->run($d->id);expect(false,'First-lock identity race must defer');}catch(ReadDeferred){}
same($io,count($state->calls),'Changed first-lock identity never reads the old provider ID');
DB::table('domains_domains')->where('id',$d->id)->update(['provider_domain_id'=>'456']);
// Fresh owned discovery requires the exact provider domain ID, never only a domain name.
DB::table('domains_domains')->where('id',$d->id)->update(['provider_domain_id'=>'999']);
same('refreshed',$observer->run($d->id),'Foreign record provider ID preserves independent domain read');same('unknown',$d->fresh()->observation['records']['families']['dns']['state'],'Identity failure is unknown');
DB::table('domains_domains')->where('id',$d->id)->update(['provider_domain_id'=>'456']);$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id);
// A successful ownership/drift read survives record rejection and quota deferral.
$observation->fault='owner';$state->calls=[];$clock->instant=$clock->now()->modify('+61 seconds');
same('unresolved',$observer->run($d->id),'Ownership loss retains unresolved drift status');
same(false,$d->fresh()->observation['facts']['owned'],'Ownership loss persists despite unavailable records');expect($d->fresh()->observation['drift'],'Ownership drift retained');same([],$state->calls,'No private record discovery after ownership loss');
$observation->fault='';$clearRecords();$observer->run($d->id);$priorInventory=$d->fresh()->observation['records'];$observation->years=2;
$limiter=new class implements \Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter {
 public int $reads=0;
 public function acquire(AccountBinding $b,RequestOrigin $o):string {if(++$this->reads>4){throw new ReadDeferred;}return 'fixture';}
 public function release(AccountBinding $b,RequestOrigin $o,string $token):void {}
};
same('read_deferred',(new DomainObservation($readBindings,$clock,$limiter,$ports))->run($d->id),'Quota defers only record stage');
same('2028-10-18',$d->fresh()->observation['facts']['expiry'],'Successful domain facts saved on record deferral');same($priorInventory,$d->fresh()->observation['records'],'Deferred record stage leaves inventory untouched');
$observation->years=1;$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id);
// Same-clock management readbacks overlay only after the snapshot sequence watermark.
Cache::flush();$op=$submit($d,$driver,'dns',['records'=>[['name'=>'www','type'=>'A','address'=>'8.8.8.8']],'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest([])]);
same('succeeded',$management->run($op->id,$driver)->state,'Separate authorized record write');
same('www',OwnerRecords::forDomain($d->fresh())['dns'][0]['name'],'Later sequence overlays same-clock inventory');
$state->dns=[];$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id);same([],OwnerRecords::forDomain($d->fresh())['dns'],'New snapshot supersedes older operation history');
Cache::flush();$op=$submit($d,$driver,'lock',['locked'=>true]);same('succeeded',$management->run($op->id,$driver)->state,'Unrelated section readback');
$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id);expect(isset(ObservationProjection::sections($d->fresh())['lock']),'Record watermark retains unrelated proven section facts');
// Partial known glue refresh, changed and absent hosts, no adoption or complete enumeration.
Cache::flush();$op=$submit($d,$driver,'glue_add',['host'=>'ns1.discovery-placeholder.com','ip'=>'8.8.8.8']);same('succeeded',$management->run($op->id,$driver)->state,'Known local child host');
$state->glue['ns1.discovery-placeholder.com']='1.1.1.1';$state->calls=[];$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id);
same('1.1.1.1',OwnerRecords::forDomain($d->fresh())['glue'][0]['ip'],'Known host refreshed through CheckNSStatus');
expect(in_array('CheckNSStatus',array_column($state->calls,0),true),'Only explicit known child queried');
$state->glue['ns1.discovery-placeholder.com']='10.0.0.1';$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id);
same('1.1.1.1',OwnerRecords::forDomain($d->fresh())['glue'][0]['ip'],'Non-public read preserves confirmed host without absence proof');expect(ObservationRecords::held($d->fresh()),'Invalid child address holds record stage');
$io=count($state->calls);$clock->instant=$clock->now()->modify('+1 day');$observer->run($d->id);same($io,count($state->calls),'Invalid child read does not retry automatically');$state->glue['ns1.discovery-placeholder.com']='1.1.1.1';
foreach(['GetHosts','GetDomainInfo','CheckNSStatus']as$fault){
 $clearRecords();$state->fault=$fault;$clock->instant=$clock->now()->modify('+61 seconds');
 same('refreshed',$observer->run($d->id),'Failed record read holds only records: '.$fault);
 same('1.1.1.1',OwnerRecords::forDomain($d->fresh())['glue'][0]['ip'],'Failed read retains confirmed partial child history');
}
$state->fault='';$clearRecords();
$state->glue=[];$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id);same([],OwnerRecords::forDomain($d->fresh())['glue'],'Exact missing-host proof removes local history');
// Driver refresh is bounded and retains known hosts beyond its per-run child read cap.
$known=[];foreach(range(1,14)as$i){$known['ns'.$i.'.discovery-placeholder.com']='8.8.8.8';}$state->glue=$known;$state->calls=[];
$b=$driver->capabilities()->binding;$ctx=new RequestContext($b,RequestOrigin::Background,$clock->now()->modify('+250 seconds'),new RequestBudget(25),$clock);
$partial=$driver->discover(new ManagementRequest($b,'bounded-glue','discovery-placeholder.com','discovery',[],'456'),$ctx,$known);
same(13,count(array_filter($state->calls,fn($r)=>$r[0]==='CheckNSStatus')),'At most13 known child hosts refreshed');
same(14,count($partial['glue']['records']),'Unchecked hosts retained explicitly partial');
$state->calls=[];$ctx=new RequestContext($b,RequestOrigin::Background,$clock->now()->modify('+250 seconds'),new RequestBudget(25),$clock);$driver->discover(new ManagementRequest($b,'rotated-glue','discovery-placeholder.com','discovery',[],'456'),$ctx,$partial['glue']['records']);
expect(in_array(['CheckNSStatus',['CheckNSName'=>'ns14.discovery-placeholder.com']],$state->calls,true),'Next turn rotates to previously unchecked child host');
// NameSilo unsupported discovery remains unknown without a fabricated empty list or wire.
$b=new AccountBinding('namesilo',2,Environment::Test,'synthetic-ns');$calls=0;
$ns=new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Management($b,function()use(&$calls){$calls++;throw new RuntimeException;});
$ctx=new RequestContext($b,RequestOrigin::Background,$clock->now()->modify('+250 seconds'),new RequestBudget(25),$clock);
$unknown=$ns->discover(new ManagementRequest($b,'observation:2','example.com','discovery',[]),$ctx);
foreach(['dns','srv','ds']as$k){same(['state'=>'unknown'],$unknown[$k],'Unsupported '.$k.' never empty');}same(0,$calls,'Unsupported discovery never calls transport');
// Existing worker provides durable fairness and no automatic retry of a failed record read.
$work=new ObservationWork($observer,$clock,$budget);$work->enroll($d->server_id);$other=$tlds[1]->server_id;$work->enroll($other);
// Cap refusal remains deferred through primary, record-family and child-host discovery catches.
$families=$d->fresh()->observation['records']['families'];$families['glue']=['state'=>'partial','records'=>['ns1.discovery-placeholder.com'=>'8.8.8.8']];
ObservationRecords::save($d->fresh(),$families,$clock->now());$state->glue=['ns1.discovery-placeholder.com'=>'8.8.8.8'];
foreach(['primary','GetHosts','CheckNSStatus'] as $stage){
    DB::table('domains_observation_work')->where('domain_id',$d->id)->update(['due_at'=>$clock->now()->getTimestamp()]);
    if($stage==='primary'){$observation->during=static fn()=>throw new \Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit;}
    else{$capHook=static function($command)use(&$capHook,$stage,$state){if($command===$stage){throw new \Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit;}$state->during=$capHook;};$state->during=$capHook;}
    $inventory=$d->fresh()->observation['records'];same('read_deferred',$work->tick(2)[$d->server_id],'Cap is deferred at '.$stage);
    $capJob=DB::table('domains_observation_work')->where('domain_id',$d->id)->sole();
    same([0,null,0],[$capJob->tries,$capJob->claim,$capJob->lease_until],'Cap spends no try and releases job at '.$stage);
    expect(!ObservationRecords::held($d->fresh()),'Cap does not create a record hold at '.$stage);same($inventory,$d->fresh()->observation['records'],'Cap preserves inventory at '.$stage);
    $clock->instant=$clock->now()->modify('+60 seconds');same('refreshed',$work->tick(2)[$d->server_id],'Capacity returns without staff resume at '.$stage);
}
DB::table('domains_observation_work')->where('domain_id',$d->id)->update(['due_at'=>$clock->now()->getTimestamp()]);
$state->fault='GetDnsSec';$clock->instant=$clock->now()->modify('+61 seconds');same('refreshed',$work->tick(1)[$d->server_id],'Record failure does not stop domain work');
expect(ObservationRecords::held($d->fresh()),'Unsupported family durably holds records');
same([$other=>'idle'],$work->tick(1),'Failing account cannot starve another account');
$io=count($state->calls);$domainIo=count($observation->calls);
foreach([2,3]as$years){$observation->years=$years;$clock->instant=$clock->now()->modify('+1 day');same('unresolved',$work->tick(2)[$d->server_id],'Daily domain refresh continues and reports expiry drift under record hold');same((2026+$years).'-10-18',$d->fresh()->observation['facts']['expiry'],'Daily expiry still updates');}
same($io,count($state->calls),'Failed record discovery does not retry automatically');expect(count($observation->calls)>$domainIo,'Independent domain reads continue');
$job=DB::table('domains_observation_work')->where('domain_id',$d->id)->sole();$work->resume($job->id,'staff_record_resume');expect(!ObservationRecords::held($d->fresh()),'Audited resume clears record hold');$work->tick(2);
expect(count($state->calls)>$io,'Explicit resume allows one record attempt');expect(ObservationRecords::held($d->fresh()),'Persistent family failure holds records again');
$io=count($state->calls);$clock->instant=$clock->now()->modify('+1 day');$work->tick(2);same($io,count($state->calls),'Resumed failed discovery does not loop');
$state->fault='';$observation->years=1;$work->resume($job->id,'staff_record_resume');$work->tick(2);
same('complete',$d->fresh()->observation['records']['families']['dns']['state'],'Bounded audited resume recovers');expect(!ObservationRecords::held($d->fresh()),'Successful resume leaves records unheld');
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
same('refreshed',$observer->run($d->id),'Host overflow holds only record publication');same($prior,$d->fresh()->observation['records'],'Host overflow cannot advance watermark');
same(51,count(ObservationProjection::sections($d->fresh())['known_glue']),'All51 confirmed hosts remain recoverable');expect(!isset(OwnerRecords::forDomain($d->fresh())['dns']),'Host overflow holds owner whole-list forms');
$observation->fault='info_missing';same('read_no_evidence',$observer->run($d->id),'Failed domain read still preserves overflowed history');same($prior,$d->fresh()->observation['records'],'Failure saving never truncates confirmed51st host');$observation->fault='';
// History overflow never publishes a snapshot that forgets later confirmed child history.
Cache::flush();$op=$submit($d,$driver,'glue_add',['host'=>'ns102.discovery-placeholder.com','ip'=>'8.8.4.4']);$management->run($op->id,$driver);
$row=$op->fresh()->getRawOriginal();unset($row['id']);
for($i=0;$i<101;$i++){$row['operation_key']=hash('sha256','overflow-'.$i);DB::table('domains_operations')->insert($row);}
$prior=$d->fresh()->observation['records'];$io=count($state->calls);$observation->years=2;
DB::table('domains_observation_work')->where('id',$job->id)->update(['due_at'=>$clock->now()->getTimestamp(),'safe_code'=>null]);
same('unresolved',$work->tick(2)[$d->server_id],'Overflow holds records while domain work continues and reports drift');
same($prior,$d->fresh()->observation['records'],'Overflow does not advance inventory or its watermark');same($io,count($state->calls),'Overflow skips record provider reads');
same('2028-10-18',$d->fresh()->observation['facts']['expiry'],'Overflow still refreshes domain drift facts');expect(!isset(OwnerRecords::forDomain($d->fresh())['dns']),'Overflow does not present stale whole-list records');
$domainIo=count($observation->calls);$observation->years=3;$clock->instant=$clock->now()->modify('+1 day');$work->tick(2);expect(count($observation->calls)>$domainIo,'Overflow retains daily domain refresh');same($io,count($state->calls),'Overflow never retries record discovery automatically');same('2029-10-18',$d->fresh()->observation['facts']['expiry'],'Overflow cannot freeze daily expiry facts');
$nativeSuccessful=true;echo "Initial managed records: complete/unknown, identity, encryption, sequence, owner gates, glue and fair held work passed.\n";
