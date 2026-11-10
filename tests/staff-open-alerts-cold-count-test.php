<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The real resource callback must count with a fixed number of queries, even on a cold cache, however many domains are alerted.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts;

staffPanel(); $domains=staffData(); Auth::setUser(staffUser([Staff::VIEW]));
// Ordinary checkout data: queued requests with a paid or an unpaid invoice, and a registration held for the registrar balance.
$alert=static function (Domain $d, string $kind): void {
    $op=$d->operations()->sole();
    // Fixture rows: the model keeps a request's action fixed once saved.
    DB::table('domains_operations')->where('id',$op->id)->update($kind==='balance'?['action'=>'register','state'=>'held','safe_code'=>'registrar_balance_insufficient']
        :['state'=>'queued','safe_code'=>null,'invoice_id'=>App\Models\Invoice::create(['user_id'=>Auth::id(),'currency_code'=>'USD','status'=>$kind])->id]);
    ObligationAlerts::record($d,'operation_review',time(),(int)$op->id);
    ObligationAlerts::record($d,'unknown_expiry',time());
};
foreach ($domains as $i=>$d) { $alert($d,['paid','pending','balance'][$i]); }
config(['cache.default'=>'array']);
$hydrated=0;
Domain::retrieved(function() use (&$hydrated):void { $hydrated++; });
Operation::retrieved(function() use (&$hydrated):void { $hydrated++; });
$measure=static function () use (&$hydrated): array {
    Cache::flush(); $hydrated=0;
    DB::connection()->flushQueryLog(); DB::connection()->enableQueryLog();
    $badge=DomainResource::getNavigationBadge();
    $all=DB::connection()->getQueryLog(); DB::connection()->disableQueryLog();
    $reads=array_values(array_filter($all,fn(array $q)=>str_contains($q['query'],'domains_')));
    return ['badge'=>$badge,'queries'=>count($all),'domainQueries'=>count($reads),
        'aggregates'=>count(array_filter($reads,fn(array $q)=>str_contains(strtolower($q['query']),'count(*)'))),'hydrated'=>$hydrated];
};
$three=$measure();
// Five more alerted requests of the same kinds: each one is a request whose invoice, balance and release check could be read.
foreach (range(1,5) as $n) {
    $source=$domains[$n%2===0?0:2]; $copy=$source->replicate();
    $copy->fill(['domain'=>"alerted-$n.example.com",'sld'=>"alerted-$n",'service_id'=>500+$n])->save();
    $op=$source->operations()->sole()->replicate(); $op->fill(['domain_id'=>$copy->id,'service_id'=>$copy->service_id,'operation_key'=>'private-alerted-'.$n])->save();
    $alert($copy,['paid','pending','balance','paid','balance'][$n-1]);
}
$eight=$measure();
echo 'Cold badge reads: '.json_encode([$three,$eight])."\n";
same(['3','8'],[$three['badge'],$eight['badge']],'Cold badge counts each alerted domain once');
same($three['queries'],$eight['queries'],'Cold badge reads as many queries for 8 alerted domains as for 3: '.json_encode([$three,$eight]));
// Since 850aef9d3 the badge shares the Open alerts rule, which reads each candidate's saved requests in PHP, 200 rows per page.
expect($eight['domainQueries']<=12,'Cold badge uses at most twelve domain SQL queries, actual: '.$eight['domainQueries']);
same([1,1],[$three['aggregates'],$eight['aggregates']],'Cold badge has one aggregate');
// Each alerted domain, its latest request, its linked requests and its waiting request load once, never per check or invoice.
expect($three['hydrated']<=4*3&&$eight['hydrated']<=4*8,'Cold badge loads each alerted domain and its requests once: '.json_encode([$three['hydrated'],$eight['hydrated']]));
$nativeSuccessful=true; echo "Cold badge uses {$eight['domainQueries']} domain queries and one aggregate for 3 or 8 alerted domains.\n";
