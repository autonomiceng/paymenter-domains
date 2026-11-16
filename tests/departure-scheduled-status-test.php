<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. All registrar, DNS and RDAP transports are synthetic.
declare(strict_types=1);
require __DIR__.'/Support/observation-records-ready.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsContext,DnsQuery};
use Paymenter\Extensions\Servers\Domains\Services\{DepartureChecks,HoldingObservationWork,HoldingReadRetry,PublicRegistryReader,RenewalOwnership};

$healthy=$d->fresh()->observation;$money=$nativeMoney();$originalBefore=$original->fresh()->getAttributes();
$failures=[];$check=static function(mixed $want,mixed $got,string $why)use(&$failures):void{if($want!==$got){$failures[]=$why.'; expected '.json_encode($want).', got '.json_encode($got);}};
$registryCalls=0;
$dns=new class implements DnsReader {
    public function read(DnsQuery $q,DnsContext $c):DnsAnswer { $c->reserve();return $q->type==='A'?DnsAnswer::rrset($q,[['host'=>$q->name,'type'=>'A','class'=>'IN','ttl'=>60,'ip'=>'8.8.8.8']]):DnsAnswer::negative($q); }
};
app()->instance(DepartureChecks::class,new DepartureChecks($dns,$clock,static function($dns,$snapshot)use(&$registryCalls,$d){
    return new PublicRegistryReader($dns,['services'=>[[['com'],['https://rdap.example.com']]]],static function()use(&$registryCalls,$d){
        same(0,DB::transactionLevel(),'Scheduled RDAP runs outside locks');$registryCalls++;
        return ['status'=>200,'body'=>json_encode(['objectClassName'=>'domain','ldhName'=>$d->domain,'status'=>['ok']])];
    });
}));
$worker=new HoldingObservationWork($observer,$clock);
DB::table('domains_observation_queues')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
$queue=DB::table('domains_observation_queues')->sole();$guard=static fn()=>null;
// Exercise both work phases independently, including a status change after the work was admitted.
foreach(['grace','expired'] as $status){foreach([$status,'active'] as $admitted){foreach(['departure_registry','departure_recheck'] as $phase){
    $case=$status.' admitted as '.$admitted.' '.$phase;
    $d->refresh()->update(['status'=>$admitted,'observation'=>$healthy]);
    HoldingObservationWork::current($d->fresh())->update(['safe_code'=>null,'tries'=>0,'claim'=>null,'lease_until'=>0]);
    $clock->instant=$clock->now()->modify('+61 seconds');$observation->fault='owner';
    same('domain_not_in_account',$observer->run($d->id,readRecords:true),'Definite snapshot absence prepares '.$case);
    $d->refresh()->update(['status'=>$status]);
    $at=$d->fresh()->observation['departure_check']['at'];
    if($phase==='departure_recheck'){
        // Isolate the registrar phase from registry-phase claim failures.
        $p=$d->fresh()->observation;$p['departure_check']['registry']=['result'=>'facts','status'=>['ok'],'at'=>$at];$d->update(['observation'=>$p]);
        $clock->instant=$clock->now()->setTimestamp($at+86400);$observation->fault='';
    }
    HoldingObservationWork::current($d->fresh())->update(['safe_code'=>$phase,'due_at'=>$clock->now()->getTimestamp(),'tries'=>0,'claim'=>null,'lease_until'=>0]);
    $beforeRegistry=$registryCalls;$beforeRegistrar=count($observation->calls);
    $result=$worker->turn($queue,$guard);$fresh=$d->fresh();$work=HoldingObservationWork::current($fresh)->sole();
    $check(false,$work->safe_code==='superseded','Supported work must not be superseded: '.$case);
    if($phase==='departure_registry'){
        $check('departure_recheck',$result,'Scheduled registry read runs: '.$case);
        $check($beforeRegistry+1,$registryCalls,'One public registry read: '.$case);
        $check($beforeRegistrar,count($observation->calls),'Registry phase does not read the registrar: '.$case);
        $check(true,isset($fresh->observation['departure_check']['registry']),'Registry evidence is saved: '.$case);
        $check($at+86400,(int)$work->due_at,'Registrar recheck keeps its 24-hour due time: '.$case);
    }else{
        // Primary ownership can recover while a separate record-inventory hold remains.
        $check(false,in_array($result,['idle','read_deferred'],true),'Scheduled registrar recheck completes: '.$case);
        $check(true,count($observation->calls)>$beforeRegistrar,'Registrar is actually read: '.$case);
        $check($beforeRegistry,$registryCalls,'Registrar recheck does not repeat RDAP: '.$case);
        $check(true,$fresh->observation['renewal_ownership']['owned']??null,'Recheck saves positive ownership: '.$case);
        $check(false,HoldingReadRetry::absent($fresh),'Positive recheck clears absence: '.$case);
        $check(false,isset($fresh->observation['departure_check']),'Positive recheck clears suspicion: '.$case);
        $check(0,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',RenewalOwnership::REVIEW)->whereNull('resolved_at')->count(),'Positive recheck resolves the alert: '.$case);
    }
    $check($status,$fresh->status,'Read work preserves the holding status: '.$case);
}}}
same($money,$nativeMoney(),'Scheduled checks do not change money');same($originalBefore,$original->fresh()->getAttributes(),'Scheduled checks preserve the purchase');
expect($failures===[],implode("\n",$failures));
$nativeSuccessful=true;echo "Grace and expired departure work runs in both phases, including status changes after admission.\n";
