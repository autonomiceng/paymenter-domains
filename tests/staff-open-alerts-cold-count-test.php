<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The real resource callback must aggregate without hydrating the detail projection, even on a cold cache.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts;

staffPanel(); $domains=staffData(); Auth::setUser(staffUser([Staff::VIEW]));
foreach ($domains as $d) {
    $op=$d->operations()->sole(); $op->update(['state'=>'queued']);
    ObligationAlerts::record($d,'operation_review',time(),(int)$op->id);
    ObligationAlerts::record($d,'unknown_expiry',time());
}
config(['cache.default'=>'array']); Cache::flush();
$hydrated=0;
Domain::retrieved(function() use (&$hydrated):void { $hydrated++; });
Operation::retrieved(function() use (&$hydrated):void { $hydrated++; });
DB::connection()->flushQueryLog(); DB::connection()->enableQueryLog();
same('3',DomainResource::getNavigationBadge(),'Cold badge counts each alerted domain once');
$reads=array_values(array_filter(DB::connection()->getQueryLog(),fn(array $q)=>str_contains($q['query'],'domains_')));
expect(count($reads)<=2,'Cold badge uses at most two domain SQL queries, actual: '.count($reads));
same(1,count(array_filter($reads,fn(array $q)=>str_contains(strtolower($q['query']),'count(*)'))),'Cold badge has one aggregate');
same(0,$hydrated,'Cold badge hydrates no domain or operation models');
DB::connection()->disableQueryLog();
$nativeSuccessful=true; echo "Cold badge uses ".count($reads)." domain queries, one aggregate and no model projection.\n";
