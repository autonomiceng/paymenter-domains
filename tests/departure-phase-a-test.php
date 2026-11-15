<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. All transports are synthetic.
declare(strict_types=1);
require __DIR__.'/Support/observation-records-ready.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{DepartureChecks,DomainStates,HoldingObservationWork,HoldingReadRetry,ObservationRecords,ObligationAlerts,PublicRegistryReader,RenewalOwnership};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsContext,DnsQuery};
require_once __DIR__.'/Support/staff-panel.php';
$money=$nativeMoney();$originalBefore=$original->fresh()->getAttributes();
$clock->instant=$clock->now()->modify('+61 seconds');$observation->fault='absent';
same('domain_not_in_account',$observer->run($d->id,readRecords:true),'A definite absence is recorded');
same('departure_suspected',DomainStates::managed($d->fresh(),null,$clock->now()->format('Y-m-d'),false)[0],'Absence derives suspected departure');
$wire=0;
$dns=new class implements DnsReader {
    public function read(DnsQuery $q,DnsContext $c):DnsAnswer { $c->reserve();return $q->type==='A'?DnsAnswer::rrset($q,[['host'=>$q->name,'type'=>'A','class'=>'IN','ttl'=>60,'ip'=>'8.8.8.8']]):DnsAnswer::negative($q); }
};
$registry=new DepartureChecks($dns,$clock,static function($dns,$snapshot)use(&$wire,$d){return new PublicRegistryReader($dns,['services'=>[[['com'],['https://rdap.example.com']]]],static function()use(&$wire,$d){same(0,DB::transactionLevel(),'RDAP runs outside locks');$wire++;return ['status'=>200,'body'=>json_encode(['objectClassName'=>'domain','ldhName'=>$d->domain,'status'=>['ok'],'events'=>[['eventAction'=>'expiration','eventDate'=>'2028-10-25']],'entities'=>[['roles'=>['registrar'],'vcardArray'=>['vcard',[['fn',[], 'text','Example Registrar']]]]]])];});});
app()->instance(DepartureChecks::class,$registry);
$worker=new HoldingObservationWork($observer,$clock);
DB::table('domains_observation_queues')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
$queue=DB::table('domains_observation_queues')->sole();$guard=static fn()=>null;
$work=static fn()=>HoldingObservationWork::current($d->fresh())->sole();
same('departure_recheck',$worker->turn($queue,$guard),'First absence queues one public registry read');
same(1,$wire,'One registry request');$first=$clock->now()->getTimestamp();
same($first+86400,(int)$work()->due_at,'Registrar recheck is due in 24 hours');
same('idle',$worker->turn($queue,$guard),'No registrar repeat before 24 hours');same(1,$wire,'No duplicate RDAP read');
$observation->fault='';$clock->instant=$clock->now()->modify('+24 hours');
same('refreshed',$worker->turn($queue,$guard),'Automatic recheck heals a false absence');
same(false,HoldingReadRetry::absent($d->fresh()),'Ownership clears absence without a person');
same(0,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','departure_review')->whereNull('resolved_at')->count(),'Ownership clears the single alert');
$clock->instant=$clock->now()->modify('+61 seconds');$observation->fault='absent';$observer->run($d->id,readRecords:true);
$at=$clock->now();
same('needs_review',DepartureChecks::attention($d->fresh(),$at->getTimestamp())['state'],'Fresh absence needs review');
same('delayed',DepartureChecks::attention($d->fresh(),$at->modify('+2 days')->getTimestamp())['state'],'Alert becomes delayed at two days');
same($d->provider_expires_at->format('Y-m-d'),DepartureChecks::attention($d->fresh(),$at->modify('+2 days')->getTimestamp())['expiry'],'Delayed alert retains expiry');
$clock->instant=$clock->now()->modify('+61 seconds');$observation->fault='transport';$observer->run($d->id,readRecords:true);
same(true,HoldingReadRetry::absent($d->fresh()),'Unknown read cannot clear definite absence');
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\OwnedDomains::class,$owners);
$actor=staffUser([Staff::VIEW,'admin.domains.manage']);staffPanel();Auth::setUser($actor);
$page=Livewire\Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$d->id]);
$page->assertActionVisible('check_departure')->assertActionEnabled('check_departure');
$observation->fault='';app()->instance(\Paymenter\Extensions\Servers\Domains\Services\DomainObservation::class,$observer);
Illuminate\Support\Facades\Schema::create('cache',function($t){$t->string('key')->primary();$t->mediumText('value');$t->integer('expiration');});
Illuminate\Support\Facades\Schema::create('cache_locks',function($t){$t->string('key')->primary();$t->string('owner');$t->integer('expiration');});
Illuminate\Support\Facades\Queue::fake();config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>900,'cache.default'=>'database']);
$page->callAction('check_departure');
Illuminate\Support\Facades\Queue::assertPushed(\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::class,function($job)use($observer){expect($job->departure,'Check again requests RDAP too');$job->handle($observer);return true;});
same(false,HoldingReadRetry::absent($d->fresh()),'Staff Check again runs the registrar read');same(2,$wire,'Check again also refreshes RDAP');
$clock->instant=$clock->now()->modify('+61 seconds');$observation->fault='absent';$observer->run($d->id,readRecords:true);
foreach(['lock'=>['locked'=>false],'auth_code_email'=>[],'contacts'=>[],'nameservers'=>['nameservers'=>['ns1.example.com','ns2.example.com']]] as $action=>$input){
    $count=\Paymenter\Extensions\Servers\Domains\Models\Operation::count();$refused=false;
    try{$management->request($user,$d->id,$action,$input,hash('sha256','suspected-'.$action),$driver);}catch(DomainException $error){same(DepartureChecks::PAUSED,$error->getMessage(),'Refusal names the departure pause');$refused=true;}
    expect($refused,'Suspected departure refuses '.$action);same($count,\Paymenter\Extensions\Servers\Domains\Models\Operation::count(),'Refusal writes no request');
}
$allowed=$management->available($user,$d->fresh(),$driver,['dns','lock','nameservers','contacts','auth_code_email']);
same(['dns'],$allowed,'DNS stays available while registrar changes pause');
$dnsOp=$management->request($user,$d->id,'dns',['records'=>[['name'=>'@','type'=>'A','address'=>'8.8.8.8']],'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest(\Paymenter\Extensions\Servers\Domains\Services\OwnerRecords::forDomain($d->fresh())['dns'],'dns')],hash('sha256','suspected-dns'),$driver);
same('queued',$dnsOp->state,'DNS request is admitted while suspected');
// A saturated public quota cannot postpone the one registrar recheck or make it repeat.
$clock->instant=$clock->now()->modify('+61 seconds');
DB::transaction(function()use($d,$clock):void{$fresh=$d->fresh();$p=$fresh->observation;$p['departure_check']['at']=$clock->now()->getTimestamp()-86400;$p['departure_check']['rechecked']=false;unset($p['departure_check']['registry']);$fresh->update(['observation'=>$p]);});
HoldingObservationWork::current($d->fresh())->update(['safe_code'=>'departure_registry','due_at'=>$clock->now()->getTimestamp(),'lease_until'=>0]);
$pool=new \Paymenter\Extensions\Servers\Domains\Services\PublicDnsQuota();$one=$pool->acquire($clock->now()->getTimestamp());$two=$pool->acquire($clock->now()->getTimestamp());
same('departure_registry',$worker->turn($queue,$guard),'The registrar recheck runs at 24 hours despite public quota saturation');
$calls=count($observation->calls);same('read_deferred',$worker->turn($queue,$guard),'Only the uncompleted registry read remains queued');
same($calls,count($observation->calls),'The automatic registrar recheck is not repeated');$pool->release($one);$pool->release($two);
same($money,$nativeMoney(),'Departure checks make no money changes');same($originalBefore,$original->fresh()->getAttributes(),'Original purchase stays intact');
$nativeSuccessful=true;echo "Departure state, automatic false-absence recovery, delayed alert, staff recheck and management guard passed.\n";
