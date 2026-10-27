<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchSource,ManagedPublicObservation};
use Paymenter\Extensions\Servers\Domains\Services\ManagedPublicWorkSchema;
extract(managedFixture(skipA3:true));$id=(int)$d->id;$uid=(int)$owner->id;$now=$time->now;
for($i=0;$i<100;$i++) {
    $o=App\Models\Order::create(['user_id'=>$uid,'currency_code'=>'USD']);
    $s=App\Models\Service::create(['user_id'=>$uid,'order_id'=>$o->id,'product_id'=>$host->product_id,'plan_id'=>$host->plan_id,
        'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
    $name='host'.$i.'.example.com';$s->properties()->create(['key'=>'domain','value'=>$name]);
    DB::transaction(fn()=>WatchSource::create(['user_id'=>$uid,'service_id'=>$s->id,'map_id'=>$map->id,'map_revision'=>1,
        'hostname'=>$name,'registrable_name'=>'example.com','watch_id'=>$watch->id,'state'=>'watch','source_digest'=>hash('sha256',$name),'last_seen_at'=>$now]));
}
expect($signals->enqueue($id,$uid),'Large predecessor batch accepted');
same(100,WatchSource::where('state','managed_link')->count(),'One relink transaction bounds native sources to one hundred');
same(1,WatchSource::where('state','watch')->count(),'Remaining predecessor source durable for next repair');
$fake->answers['example.com|NS']=managedNs();
expect($signals->observe($id,$uid,'delegation'),'Overflow still checks apex NS');
same(101,WatchSource::where('state','managed_link')->count(),'Next reconciliation repairs remaining predecessor host without an active watch');
$time->now+=900;expect($signals->observe($id,$uid,'delegation'),'Overflow stable second sample');
same(true,ManagedPublicObservation::orderByDesc('id')->first()->ns_confirmed,'Source rotation cannot prevent apex NS confirmation');
same(0,(int)DB::table(ManagedPublicWorkSchema::TABLE)->where('kind','delegation')->value('source_cursor'),'Delegation cohort stays stable independently of points');
$before=count($fake->calls);expect($signals->observe($id,$uid,'points'),'Overflow points stores partial facts');
expect(count($fake->calls)-$before<=10,'Overflow obeys per-job query bound');
expect(ManagedPublicObservation::orderByDesc('id')->first()->partial,'Omitted hosts never imply complete false');
// After rotation, long hosting chains must not consume the independent apex's turn.
$time->now+=86400;managedDue($id,$time->now,'points');$before=count($fake->calls);
expect($signals->observe($id,$uid,'points'),'Rotated cohort checks points again');
$calls=array_slice($fake->calls,$before);same(['example.com|MX','example.com|CNAME'],array_slice($calls,0,2),'Apex remains first despite host rotation');
expect(in_array('example.com|A',$calls,true) && in_array('example.com|AAAA',$calls,true),'Apex address families always checked');

// Two long chains exceed ten queries; an incomplete hosting attempt must still yield its cohort.
$oldCursor=(int)DB::table(ManagedPublicWorkSchema::TABLE)->where('kind','points')->value('source_cursor');
$next=WatchSource::where('managed_domain_id',$id)->where('state','managed_link')->where('id','>',$oldCursor)->orderBy('id')->first()
    ??WatchSource::where('managed_domain_id',$id)->where('state','managed_link')->orderBy('id')->first();
foreach(['example.com',$next->hostname]as$i=>$startHost) {
    $chain=[$startHost,'chain'.$i.'a.example.test','chain'.$i.'b.example.test','chain'.$i.'c.example.test'];
    foreach(array_slice($chain,0,3)as$j=>$name) {
        $q=new Paymenter\Extensions\Servers\Domains\Dns\DnsQuery($name,'CNAME');
        $fake->answers[$q->key()]=Paymenter\Extensions\Servers\Domains\Dns\DnsAnswer::rrset($q,[['host'=>$name,'class'=>'IN','type'=>'CNAME','target'=>$chain[$j+1],'ttl'=>300]]);
    }
    foreach(['CNAME','A','AAAA']as$type) { $q=new Paymenter\Extensions\Servers\Domains\Dns\DnsQuery($chain[3],$type);$fake->answers[$q->key()]=Paymenter\Extensions\Servers\Domains\Dns\DnsAnswer::negative($q); }
}
$time->now+=86400;managedDue($id,$time->now,'points');$before=count($fake->calls);
expect($signals->observe($id,$uid,'points'),'Long-chain cohort attempt saved as partial');
same(10,count($fake->calls)-$before,'Long chains retain the ten-query ceiling');
same((int)$next->id,(int)DB::table(ManagedPublicWorkSchema::TABLE)->where('kind','points')->value('source_cursor'),'Incomplete hosting attempt yields to the next cohort');
expect(ManagedPublicObservation::orderByDesc('id')->first()->partial,'Truncated hosting cohort never infers complete false');
$nativeSuccessful=true;echo "Large managed source cohorts retain stable NS confirmation and bounded partial points rotation.\n";
