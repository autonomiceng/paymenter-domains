<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic quota pressure on one account's holdings.
declare(strict_types=1);
require __DIR__.'/Support/observation-records-ready.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{DepartureChecks,HoldingObservationWork,PublicDnsQuota};
[$other]=$holding(placeholderComFixtureName('departure-other-placeholder.com'));
$clock->instant=$clock->now()->modify('+2 hours');$observation->name=$d->domain;$observation->fault='absent';
same('domain_not_in_account',$observer->run($d->id,readRecords:true),'One holding enters departure review');
DB::table('domains_observation_queues')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
$queue=DB::table('domains_observation_queues')->sole();$worker=new HoldingObservationWork($observer,$clock);$guard=static fn()=>null;
HoldingObservationWork::current($d->fresh())->update(['safe_code'=>'departure_registry','due_at'=>$clock->now()->getTimestamp()-7200,'lease_until'=>0]);
HoldingObservationWork::queueInitial($other,$clock->now());
HoldingObservationWork::current($other)->update(['safe_code'=>'refreshed','due_at'=>$clock->now()->getTimestamp()-3601,'lease_until'=>0]);
app()->instance(DepartureChecks::class,new DepartureChecks(new class implements \Paymenter\Extensions\Servers\Domains\Contracts\DnsReader {
    public function read(\Paymenter\Extensions\Servers\Domains\Dns\DnsQuery $query,\Paymenter\Extensions\Servers\Domains\Dns\DnsContext $context):\Paymenter\Extensions\Servers\Domains\Dns\DnsAnswer { throw new RuntimeException('Quota saturation must prevent DNS reads.'); }
},$clock));
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
$nativeSuccessful=true;echo "Deferred departure registry work yields to other overdue holdings.\n";
