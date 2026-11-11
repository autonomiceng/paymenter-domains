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
$first=$work($new,'2026-11-10T06:10:00+00:00');
$worker=(new ReflectionClass(HoldingObservationWork::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(HoldingObservationWork::class,'clock'))->setValue($worker,$clock);
$claim=static fn()=>(new ReflectionMethod(HoldingObservationWork::class,'claim'))->invoke($worker,(object)['server_id'=>3,'account_fingerprint'=>$bind['account_fingerprint']],static fn()=>null);
$job=$claim();
same($first,(int)($job->id??0),'The first record read of a newly active domain goes ahead of older routine reads');
same(1,(int)DB::table('domains_observation_work')->where('id',$first)->value('tries'),'The claimed first read counts its try');
// Once leased, the next turn returns to the oldest routine read.
same($routine[0],(int)($claim()->id??0),'Routine reads keep oldest-first order');
// Work that is not due yet is never pulled forward, even for a first read.
DB::table('domains_observation_work')->update(['claim'=>null,'lease_until'=>0,'tries'=>0]);
DB::table('domains_observation_work')->where('id',$first)->update(['due_at'=>$now->getTimestamp()+60]);
same($routine[0],(int)($claim()->id??0),'A first read that is not due waits its turn');
// An all-routine window keeps oldest-first order and reads domain rows in one batch.
DB::table('domains_observation_work')->update(['claim'=>null,'lease_until'=>0,'tries'=>0]);
for($n=10;$n<35;$n++){$routine[]=$work($domain('routine-window-placeholder-'.$n,$n,true),'2026-11-10T05:46:00+00:00');}
DB::table('domains_observation_work')->where('id',$first)->update(['due_at'=>$now->getTimestamp()]);
DB::flushQueryLog();DB::enableQueryLog();
same($routine[0],(int)($claim()->id??0),'A first read beyond the 25-row window does not jump the queue');
$reads=array_filter(DB::getQueryLog(),fn($q)=>str_contains($q['query'],'from "domains_domains"'));
DB::disableQueryLog();
same(1,count($reads),'The claim batch-loads its bounded domain window once');
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
