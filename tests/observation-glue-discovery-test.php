<?php
// Full native observation matrix; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/observation-records-ready.php';
require_once __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationProjection,ObservationRecords,ObservationSchema,ObservationWork,OwnerRecords,ReadDeferred};
// Independent watermark/glue scenario starts from proven empty record lists.
$state->dns=[];$state->srv=[];$state->ds=[];$clock->instant=$clock->now()->modify('+61 seconds');same('refreshed',$observer->run($d->id,readRecords:true),'Empty current record premise');
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
// Scheduled holding reads refresh records as well as core facts under the shared budget.
require_once __DIR__.'/Support/observation-work.php';
$work=observationFixtureScheduler($observer,$clock,activeFixturePorts($bindings));$work->enroll($d->server_id);
$before=$d->fresh()->observation['records'];$io=count($state->calls);$domainIo=count($observation->calls);
$clock->instant=$clock->now()->modify('+1 day');$work->tick(2);
expect(count($state->calls)>$io,'Daily holding work refreshes registrar records');
$daily=$d->fresh()->observation['records']['families'];
foreach(['dns','srv','ds'] as $family){same($before['families'][$family],$daily[$family],'Daily work retains unchanged '.$family.' inventory');}
same('partial',$daily['glue']['state'],'Known child refresh never claims full glue enumeration');
same(['ns1.discovery-placeholder.com'=>'8.8.8.8'],$daily['glue']['records'],'Daily work refreshes the saved child candidate from the current registrar reply');
same($clock->now()->format(DATE_ATOM),$d->fresh()->observation['records']['at'],'Daily work advances the successful record observation time');
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
 public int $locks=0;public array $traces=[];
 protected function compileLock(\Illuminate\Database\Query\Builder $q,$value){$this->locks++;$this->traces[]=array_map(static fn($frame)=>(isset($frame['file'])?basename($frame['file']).':'.($frame['line']??0):'internal').':'.($frame['function']??''),debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));throw new RuntimeException('Unexpected row lock');}
};
$conn->setQueryGrammar($strict);
try {
 $html=$page();same(1,storefrontDom($html)->query('//form[contains(@action,"/manage/dns")]')->length,'Advisory form remains available without locking reads');same(0,$strict->locks,'GET never attempts a row lock: '.json_encode($strict->traces));
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
same('unresolved',$work->tick(2)[$d->server_id]['holding'],'Overflow holds records while domain work continues and reports drift');
same($prior,$d->fresh()->observation['records'],'Overflow does not advance inventory or its watermark');same($io,count($state->calls),'Overflow skips record provider reads');
same('2028-10-18',$d->fresh()->observation['facts']['expiry'],'Overflow still refreshes domain drift facts');expect(!isset(OwnerRecords::forDomain($d->fresh())['dns']),'Overflow does not present stale whole-list records');
$domainIo=count($observation->calls);$observation->years=3;$clock->instant=$clock->now()->modify('+1 day');$work->tick(2);expect(count($observation->calls)>$domainIo,'Overflow retains daily domain refresh');same($io,count($state->calls),'Overflow never retries record discovery automatically');same('2029-10-18',$d->fresh()->observation['facts']['expiry'],'Overflow cannot freeze daily expiry facts');
$nativeSuccessful=true;echo "Record watermark, known and discovered glue, advisory locks and history caps passed.\n";
