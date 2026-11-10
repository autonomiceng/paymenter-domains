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
    $d=Domain::create([...$bind,'domain'=>$name.'.com','sld'=>$name,'tld'=>'com','user_id'=>800+$n,'service_id'=>800+$n,'status'=>'active','observation'=>[]]);
    if($read){$d->update(['observation'=>['records'=>['identity'=>ObservationRecords::identity($d),'at'=>'2026-11-09T06:00:00+00:00',
        'last_complete_at'=>'2026-11-09T06:00:00+00:00','sequence'=>0,'families'=>[]]]]);}
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
$nativeSuccessful=true;echo "First record read priority passed.\n";
