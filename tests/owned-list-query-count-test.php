<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline query counts, not a latency benchmark.
declare(strict_types=1);
require __DIR__.'/Support/owned.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Models\WatchName;

$base=$newTransfer('query-base-placeholder.com',0,false,false)->domain;
// Retained paused watches remain on the owned list without an active hosting source.
$watchBase=DB::transaction(fn()=>WatchName::create(['user_id'=>$user->id,'canonical_name'=>'query-watch.example.com',
    'psl_revision'=>str_repeat('a',64),'state'=>'paused','notice_preferences'=>[]]));
storefrontViews();
$controller=new OwnedDomainController($owners);$session=app('request')->session();
$clone=static function(string $table,int $id,array $changes=[]):int {
    $row=(array)DB::table($table)->where('id',$id)->first();unset($row['id']);
    return (int)DB::table($table)->insertGetId([...$row,...$changes]);
};
$measure=static function($actor)use($controller,$session):array {
    $request=Request::create('/domains','GET');$request->setUserResolver(fn()=>$actor);$request->setLaravelSession($session);
    DB::flushQueryLog();DB::enableQueryLog();
    try {
        $page=$controller->index($request);$html=$page->render();$queries=DB::getQueryLog();
    } finally { DB::disableQueryLog();DB::flushQueryLog(); }
    $batches=['managed'=>0,'watch'=>0];
    foreach ($queries as $query) {
        foreach (['managed'=>'domains_domains','watch'=>'domains_watch_names'] as $kind=>$table) {
            if (str_starts_with($query['query'],'select * from "'.$table.'" where "user_id" = ? and "id" in (')) { $batches[$kind]++; }
        }
    }
    return ['queries'=>count($queries),'batches'=>$batches,'total'=>$page->getData()['domains']->total(),'visible'=>storefrontDom($html)->query('//tbody/tr')->length];
};
// Catalogue transfer availability adds fixed page reads, independent of owned row batches.
$emptyQueries=$measure(commerceUser())['queries'];
expect($emptyQueries<=50,'Fixed catalogue reads stay bounded: '.$emptyQueries);
// A renewal still being confirmed with the registrar: a sealed attempt with no answer, pending or uncertain.
$baseOp=$base->operations()->sole();$started=$clock->now()->modify('-3 days');
$renewing=static function(int $domain,int $service,string $key,int $i)use($clone,$baseOp,$started):void {
    $attempt=['claim_key'=>'claim-'.$key,'dispatch_attempted_at'=>$started->modify('+10 seconds')->format(DATE_ATOM),'seal'=>'sealed'];
    $clone('domains_operations',(int)$baseOp->id,['domain_id'=>$domain,'service_id'=>$service,'action'=>'renew','state'=>$i%2?'pending':'uncertain',
        'operation_key'=>$key,'invoice_id'=>null,'invoice_item_id'=>null,'lease_expires_at'=>null,'started_at'=>$started->format('Y-m-d H:i:s'),
        'request_fingerprint'=>hash('sha256',$key),'attempt'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode($attempt,JSON_THROW_ON_ERROR))]);
};
$expiry=$clock->now()->modify('+200 days')->format('Y-m-d');
$results=[];
foreach (['managed','watch','mixed','renewing'] as $kind) {
    $actor=commerceUser();$counts=['managed'=>0,'watch'=>0];
    $managedOrder=$clone('orders',(int)$base->service->order_id,['user_id'=>$actor->id]);
    for ($i=1;$i<=1000;$i++) {
        $managed=$kind==='managed'||$kind==='renewing'||($kind==='mixed'&&$i%2===1);
        $name=$kind.'-'.$i.'.example.com';
        if ($managed) {
            $service=$clone('services',(int)$base->service_id,['user_id'=>$actor->id,'order_id'=>$managedOrder]);
            $domain=$clone('domains_domains',(int)$base->id,['user_id'=>$actor->id,'service_id'=>$service,'domain'=>$name,'reservation_name'=>$name,'sld'=>$kind.'-'.$i,'tld'=>'example.com',
                ...($kind==='renewing'?['status'=>'active','provider_expires_at'=>$expiry.' 00:00:00']:[])]);
            if ($kind==='renewing') { $renewing($domain,$service,'renew:'.$domain.':g'.$base->authority_generation.':'.$expiry,$i); }
        } else {
            $clone('domains_watch_names',(int)$watchBase->id,['user_id'=>$actor->id,'canonical_name'=>$name]);
        }
        $counts[$managed?'managed':'watch']++;
        if (!in_array($i,[25,250,251,1000],true)) { continue; }
        // Warm Blade and application services before counting controller-through-render queries.
        if ($i===25) { $measure($actor); }
        $result=$measure($actor);$results[$kind][$i]=$result['queries'];
        same($i,$result['total'],'Every owned '.$kind.' row is resolved at '.$i);
        same(25,$result['visible'],'Only the requested page is rendered at '.$i);
        same(['managed'=>(int)ceil($counts['managed']/250),'watch'=>(int)ceil($counts['watch']/250)],$result['batches'],'Both summary loops keep 250-row batches at '.$kind.' '.$i);
        expect($result['queries']<=$emptyQueries+70*array_sum($result['batches']),'Query count stays bounded per batch at '.$kind.' '.$i.' ('.$result['queries'].' reads, '.$emptyQueries.' fixed)');
    }
    same($results[$kind][25],$results[$kind][250],'No per-row queries within a full '.$kind.' batch');
    if ($kind==='mixed') { same($results[$kind][250],$results[$kind][251],'Mixed list stays within its two existing batches'); }
    else {
        $extra=$results[$kind][251]-$results[$kind][250];expect($extra>0,'Crossing 250 rows adds one summary batch');
        same($results[$kind][25]+3*$extra,$results[$kind][1000],'Four '.$kind.' batches add queries linearly');
    }
}
echo 'Owned list query counts (SQLite, controller through Blade, no latency claim): '.json_encode($results,JSON_THROW_ON_ERROR)."\n";
$nativeSuccessful=true;
