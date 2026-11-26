<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar, DNS and RDAP transports are synthetic.
declare(strict_types=1);
require __DIR__.'/Support/observation-records-ready.php';
require_once __DIR__.'/Support/staff-panel.php';
require_once __DIR__.'/Support/fixture-cases.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsContext,DnsQuery};
use Paymenter\Extensions\Servers\Domains\Presentation\{DsState,OwnedDomain,Plain};
use Paymenter\Extensions\Servers\Domains\Storefront\Search\PublicDnsQuota;
use Paymenter\Extensions\Servers\Domains\Services\{DepartureChecks,DomainStates,HoldingObservationWork,HoldingReadRetry,PublicRegistryReader,RenewalOwnership};
use Paymenter\Extensions\Servers\Domains\Holding\Observation\ObservationRecords;
// Every case starts from the same registered holding with a complete initial inventory. Cases read departure
// evidence through different projections and at different read clocks; each pins its own clock first.
// The staff panel routes are registered once, before the checkpoint, so no case adds routes the reset cannot remove.
staffPanel();
$pinned=[$clock->now(),Carbon\Carbon::getTestNow(),Carbon\CarbonImmutable::getTestNow()];
$pin=static function()use($clock,$pinned):void{[$clock->instant,$native,$immutable]=$pinned;Carbon\Carbon::setTestNow($native);Carbon\CarbonImmutable::setTestNow($immutable);};
$fixed=compact('d','original','clock','observation','observer','owners','holding','nativeMoney','pin');

// Grace and expired exception-based absence survives unknown reads with no proof or an older positive proof.
$cases['absence-status']=static function()use($fixed):void {extract($fixed);
    $pin();
    $healthy=$d->fresh()->observation;$money=$nativeMoney();$originalBefore=$original->fresh()->getAttributes();
    $failures=[];$check=static function(mixed $want,mixed $got,string $why)use(&$failures):void{if($want!==$got){$failures[]=$why;}};
    foreach(['grace','expired'] as $status){foreach(['none','owned'] as $prior){
        $case=$status.' with '.$prior.' retained proof';$saved=$healthy;
        if($prior==='none'){unset($saved['renewal_ownership']);}
        $d->refresh()->update(['status'=>$status,'observation'=>$saved]);
        $clock->instant=$clock->now()->modify('+61 seconds');$observation->fault='absent';
        same('domain_not_in_account',$observer->run($d->id,readRecords:true),'The real exception classifier records absence: '.$case);
        $absentAt=$clock->now()->format(DATE_ATOM);
        foreach(['definite absence','unknown read'] as $step){
            if($step==='unknown read'){
                $clock->instant=$clock->now()->modify('+61 seconds');$observation->fault='transport';
                same('read_failed',$observer->run($d->id,readRecords:true),'A transport error replaces only the diagnostic: '.$case);
                $check('read_failed',$d->fresh()->observation['record_failure']['code'],'The new diagnostic is saved: '.$case);
            }
            $fresh=$d->fresh();$label=$case.' after '.$step;
            $check(false,$fresh->observation['renewal_ownership']['owned']??null,'Definite absence is retained: '.$label);
            $check($absentAt,$fresh->observation['renewal_ownership']['at']??null,'Unknown reads keep the absence time: '.$label);
            $check(true,HoldingReadRetry::absent($fresh),'Absence survives without positive ownership: '.$label);
            $check(true,RenewalOwnership::blocked($fresh),'Collection remains fenced: '.$label);
            $check(true,DepartureChecks::suspected($fresh),'Departure stays suspected: '.$label);
            $check('departure_suspected',DomainStates::managed($fresh,null,$clock->now()->format('Y-m-d'),false)[0],'Derived state is retained: '.$label);
            $check(1,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',RenewalOwnership::REVIEW)->whereNull('resolved_at')->count(),'The single review alert stays open: '.$label);
        }
        $clock->instant=$clock->now()->modify('+61 seconds');$observation->fault='';
        same('refreshed',$observer->run($d->id,readRecords:true),'Positive same-identity ownership is read: '.$case);
        $fresh=$d->fresh();
        $check(false,HoldingReadRetry::absent($fresh),'Positive ownership clears absence: '.$case);
        $check(false,RenewalOwnership::blocked($fresh),'Positive ownership releases the collection fence: '.$case);
        $check(false,DepartureChecks::suspected($fresh),'Positive ownership clears suspicion: '.$case);
        $check(0,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',RenewalOwnership::REVIEW)->whereNull('resolved_at')->count(),'Positive ownership resolves the alert: '.$case);
        $check($status,$fresh->status,'Read results preserve the holding status: '.$case);
    }}
    same($money,$nativeMoney(),'Absence retention changes no money');same($originalBefore,$original->fresh()->getAttributes(),'Absence retention preserves the purchase');
    expect($failures===[],implode("\n",$failures));
};

// Paused DNSSEC retains its observed state and records.
$cases['ds-records']=static function()use($fixed):void {extract($fixed);
    $pin();
    // Present the retained inventory with NameSilo's default unsupported DS-change policy.
    $record=$d->fresh();$record->registrar_key='namesilo';
    $p=$record->observation;$p['records']=[...$p['records'],'identity'=>ObservationRecords::identity($record),
        'format'=>1,'capability_revision'=>'namesilo-records-1',
        'expected_families'=>['dns'=>'unsupported','srv'=>'unsupported','ds'=>'supported','glue'=>'supported']];
    $record->observation=$p;
    $domain=DsState::owner($owners->present($d->fresh()),$record,true);
    $domain=new OwnedDomain(...[...get_object_vars($domain),'departureSuspected'=>true]);
    same(true,$domain->management['ds']['off'],'DS changes remain unsupported');
    same([],$domain->management['ds']['actions'],'Unsupported DS changes expose no actions');
    $records=$domain->management['ds']['records'];expect(!empty($records),'The policy retains observed DS records');
    $html=view('domains::partials.owner-management',['domain'=>$domain,'caps'=>['ds']])->render();
    $dom=Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8">'.$html,LIBXML_NOERROR);
    $row=$dom->getElementById('ds');
    expect($row!==null&&str_contains($row->textContent,$records[0]['digest']),'Paused DNSSEC exposes the retained DS digest');
    expect(str_contains($row->textContent,Plain::On->label()),'Nonempty retained DS inventory keeps DNSSEC On');
    expect($row->querySelector('button[aria-disabled="true"][type="button"]')!==null,'Departure keeps DS changes paused');
};

// Delayed departure list renders without an expiry.
$cases['list-expiry']=static function()use($fixed):void {extract($fixed);
    $pin();
    $clock->instant=$clock->now()->modify('+61 seconds');$observation->fault='absent';
    same('domain_not_in_account',$observer->run($d->id,readRecords:true),'Registrar absence starts the departure check');
    DB::transaction(fn()=>$d->fresh()->update(['provider_expires_at'=>null]));
    $clock->instant=$clock->now()->modify('+2 days');
    $actor=staffUser([Staff::VIEW]);
    $list=Livewire\Livewire::actingAs($actor)->test(ListDomains::class)->assertSuccessful()->assertCanSeeTableRecords([$d]);
    $record=$list->instance()->getTableRecords()->firstWhere('id',$d->id);
    $note=$list->instance()->getTable()->getColumn('note')->record($record);
    // Exercise the real formatter under Laravel's warning-to-exception handler.
    $html=(string)$note->formatState($note->getState());
    expect(trim(strip_tags($html))!=='','A delayed holding without expiry renders its attention item');
};

// Deferred departure registry work yields to other overdue holdings.
$cases['registry-fairness']=static function()use($fixed):void {extract($fixed);
    $pin();
    [$other]=$holding(placeholderComFixtureName('departure-other-placeholder.com'));
    $clock->instant=$clock->now()->modify('+2 hours');$observation->name=$d->domain;$observation->fault='absent';
    same('domain_not_in_account',$observer->run($d->id,readRecords:true),'One holding enters departure review');
    DB::table('domains_observation_queues')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
    $queue=DB::table('domains_observation_queues')->sole();$worker=new HoldingObservationWork($observer,$clock);$guard=static fn()=>null;
    HoldingObservationWork::current($d->fresh())->update(['safe_code'=>'departure_registry','due_at'=>$clock->now()->getTimestamp()-7200,'lease_until'=>0]);
    HoldingObservationWork::queueInitial($other,$clock->now());
    HoldingObservationWork::current($other)->update(['safe_code'=>'refreshed','due_at'=>$clock->now()->getTimestamp()-3601,'lease_until'=>0]);
    $dns = new class implements DnsReader {
        public int $calls = 0;
        public function read(DnsQuery $query, DnsContext $context): DnsAnswer
        {
            $this->calls++;
            $context->reserve();
            return $query->type === 'A'
                ? DnsAnswer::rrset($query, [['host'=>$query->name, 'type'=>'A', 'class'=>'IN', 'ttl'=>60, 'ip'=>'8.8.8.8']])
                : DnsAnswer::negative($query);
        }
    };
    $requests = [];
    $transport = static function (string $url) use (&$requests): array {
        $requests[] = $url;
        if ($url === PublicRegistryReader::BOOTSTRAP && count($requests) > 1) {
            return ['status'=>200, 'body'=>json_encode(['services'=>[[['com'], ['https://rdap.example.com']]]])];
        }
        return ['status'=>503, 'body'=>''];
    };
    app()->instance(DepartureChecks::class, new DepartureChecks($dns, $clock, static fn($dns) => new PublicRegistryReader($dns, null, $transport)));
    $pool=new PublicDnsQuota();$one=$pool->acquire($clock->now()->getTimestamp());$two=$pool->acquire($clock->now()->getTimestamp());
    expect($one!==null&&$two!==null,'Both public read slots are occupied');
    same('read_deferred',$worker->turn($queue,$guard),'Registry pressure defers the oldest holding');
    $clock->instant=$clock->now()->modify('+61 seconds');
    $observation->fault='';$observation->name=$other->domain;
    $before=count($observation->calls);
    same('refreshed',$worker->turn($queue,$guard),'The next account turn reads the other overdue holding');
    expect(count($observation->calls)>$before,'The other holding receives a registrar read');
    expect($other->fresh()->last_synced_at!==null,'The other holding saves its observation');
    $pool->release($one);$pool->release($two);
    same(0, $dns->calls, 'Quota saturation prevents DNS requests');
    same('read_deferred', $worker->turn($queue, $guard), 'An IANA outage keeps the departure registry check due');
    same(false, isset($d->fresh()->observation['departure_check']['registry']), 'Bootstrap failure saves no registry answer');
    $clock->instant = $clock->now()->modify('+60 seconds');
    same('departure_recheck', $worker->turn($queue, $guard), 'Recovered routes let the registry check finish');
    same([PublicRegistryReader::BOOTSTRAP, PublicRegistryReader::BOOTSTRAP, 'https://rdap.example.com/domain/'.$d->domain], $requests, 'Retry reaches the registry after fetching routes');
    same('endpoint_failed', $d->fresh()->observation['departure_check']['registry']['reason'], 'Registry endpoint failure is retained');
};

// Grace and expired departure work runs in both phases, including status changes after admission.
$cases['scheduled-status']=static function()use($fixed):void {extract($fixed);
    $pin();
    $healthy=$d->fresh()->observation;$money=$nativeMoney();$originalBefore=$original->fresh()->getAttributes();
    $failures=[];$check=static function(mixed $want,mixed $got,string $why)use(&$failures):void{if($want!==$got){$failures[]=$why.'; expected '.json_encode($want).', got '.json_encode($got);}};
    $registryCalls=0;
    $dns=new class implements DnsReader {
        public function read(DnsQuery $q,DnsContext $c):DnsAnswer { $c->reserve();return $q->type==='A'?DnsAnswer::rrset($q,[['host'=>$q->name,'type'=>'A','class'=>'IN','ttl'=>60,'ip'=>'8.8.8.8']]):DnsAnswer::negative($q); }
    };
    app()->instance(DepartureChecks::class,new DepartureChecks($dns,$clock,static function($dns)use(&$registryCalls,$d){
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
};
nativeFixtureCases($cases,compact('clock','observation','state','observer','budget','owners','d','original','user'));
$nativeSuccessful=true;echo "Departure observation keeps each projection and read clock.\n";
