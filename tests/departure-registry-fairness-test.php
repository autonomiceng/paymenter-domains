<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic quota pressure on one account's holdings.
declare(strict_types=1);
require __DIR__.'/Support/observation-records-ready.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsContext,DnsQuery};
use Paymenter\Extensions\Servers\Domains\Services\{DepartureChecks,HoldingObservationWork,PublicDnsQuota,PublicRegistryReader};
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
$nativeSuccessful=true;echo "Deferred departure registry work yields to other overdue holdings.\n";
