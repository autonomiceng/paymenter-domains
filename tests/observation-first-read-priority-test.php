<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. A new domain's first record read does not wait behind routine reads.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{FoundationSchema,HoldingObservationWork,ObservationRecords,ObservationSchema};
FoundationSchema::install();ObservationSchema::install();
$now=new DateTimeImmutable('2026-11-10T06:30:00+00:00');
$clock=new class($now) implements Paymenter\Extensions\Servers\Domains\Contracts\Clock {
    public function __construct(public DateTimeImmutable $at) {}
    public function now(): DateTimeImmutable { return $this->at; }
};
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$bind=['server_id'=>3,'registrar_key'=>'enom','account_fingerprint'=>str_repeat('a',64),'authority_generation'=>1];
// Routine domains already finished a record read; the new one has not.
$domain=static function(string $name,int $n,bool $read)use($bind):Domain {
    // The claim reads only domain rows and their work, so the fixture skips the account binding check.
    $d=Domain::withoutEvents(static fn()=>Domain::create([...$bind,'domain'=>$name.'.com','sld'=>$name,'tld'=>'com','user_id'=>800+$n,'service_id'=>800+$n,'status'=>'active','observation'=>[]]));
    if($read){Domain::withoutEvents(static fn()=>$d->update(['observation'=>['records'=>['identity'=>ObservationRecords::identity($d),'at'=>'2026-11-09T06:00:00+00:00',
        'last_complete_at'=>'2026-11-09T06:00:00+00:00','sequence'=>0,'families'=>[]]]]));}
    return $d->fresh();
};
$work=static function(Domain $d,string $due)use($bind):int {
    return (int)DB::table('domains_observation_work')->insertGetId([...array_diff_key($bind,['registrar_key'=>1,'authority_generation'=>1]),
        'domain_id'=>$d->id,'generation'=>1,'phase'=>HoldingObservationWork::phase($d),'due_at'=>(new DateTimeImmutable($due))->getTimestamp()]);
};
$routine=[];
foreach(range(1,6) as $n){$d=$domain('routine-read-placeholder-'.$n,$n,true);$routine[]=$work($d,'2026-11-10T05:46:00+00:00');}
$new=$domain('first-read-placeholder',9,false);
expect(ObservationRecords::current($new)===null&&(ObservationRecords::current(Domain::find(1))['last_complete_at']??null)!==null,'Fixture: routine reads finished, the new read has not');
HoldingObservationWork::queueInitial($new,$now->modify('-20 minutes'));
$first=(int)HoldingObservationWork::current($new)->value('id');
$worker=(new ReflectionClass(HoldingObservationWork::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(HoldingObservationWork::class,'clock'))->setValue($worker,$clock);
$claim=static fn()=>(new ReflectionMethod(HoldingObservationWork::class,'claim'))->invoke($worker,(object)['server_id'=>3,'account_fingerprint'=>$bind['account_fingerprint']],static fn()=>null);
$job=$claim();
same($first,(int)($job->id??0),'The first record read of a newly active domain goes ahead of older routine reads');
same(1,(int)DB::table('domains_observation_work')->where('id',$first)->value('tries'),'The claimed first read counts its try');
$leased=HoldingObservationWork::current($new)->sole();
HoldingObservationWork::queueInitial($new,$now);
same((array)$leased,(array)HoldingObservationWork::current($new)->sole(),'Repeated enrollment preserves an existing initial read and its lease');
// Once leased, the next turn returns to the oldest routine read.
same($routine[0],(int)($claim()->id??0),'Routine reads keep oldest-first order');
// Work that is not due yet is never pulled forward, even for a first read.
DB::table('domains_observation_work')->update(['claim'=>null,'lease_until'=>0,'tries'=>0]);
DB::table('domains_observation_work')->where('id',$first)->update(['due_at'=>$now->getTimestamp()+60]);
same($routine[0],(int)($claim()->id??0),'A first read that is not due waits its turn');
// A backlog larger than the bounded window must not hide a newly active domain. Domain reads stay batched.
DB::table('domains_observation_work')->update(['claim'=>null,'lease_until'=>0,'tries'=>0]);
for($n=10;$n<35;$n++){$routine[]=$work($domain('routine-window-placeholder-'.$n,$n,true),'2026-11-10T05:46:00+00:00');}
DB::table('domains_observation_work')->where('id',$first)->update(['due_at'=>$now->getTimestamp()]);
DB::flushQueryLog();DB::enableQueryLog();
$priority=$claim();
same($first,(int)($priority->id??0),'A newly active domain gets its first read even behind more than 25 older routine jobs');
$reads=array_filter(DB::getQueryLog(),fn($q)=>str_contains($q['query'],'from "domains_domains"'));
DB::disableQueryLog();
same(1,count($reads),'The claim batch-loads its bounded domain window once');
// A busy account defers this same initial read without losing its place or spending a retry.
(new ReflectionMethod(HoldingObservationWork::class,'finishCurrent'))->invoke($worker,$priority,'read_deferred',null);
same(0,(int)HoldingObservationWork::current($new)->value('tries'),'Local account deferral spends no registrar retry');
same($routine[0],(int)($claim()->id??0),'A deferred first read respects its retry due time');
$clock->at=$now->modify('+60 seconds');
same($first,(int)($claim()->id??0),'The first read keeps priority when its local deferral ends');
// A large retry backlog must not hide a newly active first attempt either.
DB::table('domains_observation_work')->whereIn('id',$routine)->update(['safe_code'=>'read_failed','tries'=>1,'claim'=>null,'lease_until'=>0]);
HoldingObservationWork::current($new)->update(['claim'=>null,'lease_until'=>0]);
same($first,(int)($claim()->id??0),'Initial reads precede even more than 25 older transient retries');
// Failed first reads still recover ahead of routine refreshes, once their backoff ends.
DB::table('domains_observation_work')->whereIn('id',$routine)->update(['safe_code'=>'refreshed','tries'=>0]);
foreach (['read_failed','read_deadline'] as $code) {
    HoldingObservationWork::current($new)->update(['safe_code'=>$code,'tries'=>1,'claim'=>null,'lease_until'=>0,'due_at'=>$clock->now()->getTimestamp()+60]);
    same($routine[0],(int)($claim()->id??0),'A transient first-read failure respects backoff: '.$code);
    // Return that routine job to the backlog for the next selection.
    DB::table('domains_observation_work')->where('id',$routine[0])->update(['claim'=>null,'lease_until'=>0]);
    $clock->at=$clock->at->modify('+60 seconds');
    same($first,(int)($claim()->id??0),'A due first-read retry precedes routine work: '.$code);
}
$clock->at=$now;
// Saved history that fails validation, here a completed read stamped after now, is not a first read and does not
// take the place of a domain that has never been read.
DB::table('domains_observation_work')->delete();
$future=$domain('future-read-placeholder',40,false);
Domain::withoutEvents(static fn()=>$future->update(['observation'=>['records'=>['identity'=>ObservationRecords::identity($future),
    'at'=>'2026-11-11T06:00:00+00:00','last_complete_at'=>'2026-11-11T06:00:00+00:00','sequence'=>0,'families'=>[]]]]));
expect(ObservationRecords::current($future->fresh())===null,'Fixture: the future stamped read fails validation');
$work(Domain::find(1),'2026-11-10T05:30:00+00:00');$work($future->fresh(),'2026-11-10T05:40:00+00:00');$genuine=$work($new,'2026-11-10T06:00:00+00:00');
same($genuine,(int)($claim()->id??0),'A genuine first read goes ahead of saved history that fails validation');
$nativeSuccessful=true;echo "First record read priority passed.\n";
