<?php
// Native render-local authorization regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ListOperations;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{PaidUncertainty,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
staffPanel();[$held,$active,$long]=staffData();
$viewer=staffUser([Staff::VIEW,Staff::COSTS,Staff::METADATA])->load('role');Auth::setUser($viewer);
$op=$held->operations()->sole();
// A correction row exercises domain lookup and both paid-summary branches across the set.
DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'uncertain','funding_basis'=>'paid_invoice']);
$op->refresh();$op->policy=['management'=>['correction_link'=>['operation_key'=>'missing']]];
// Encrypted policy fixtures retain model immutability by changing raw synthetic bytes only.
DB::table('domains_operations')->where('id',$op->id)->update(['policy'=>$op->getAttributes()['policy']]);$op->refresh();
function measuredRefresh($page, string $label): array
{
    DB::enableQueryLog();DB::flushQueryLog();$page->call('$refresh')->assertSuccessful();
    $queries=DB::getQueryLog();DB::disableQueryLog();
    $count=static fn(string $table)=>count(array_filter($queries,static fn($q)=>preg_match('/\bfrom\s+["`]?'.preg_quote($table,'/').'["`]?\b/i',$q['query'])));
    $counts=[$count('users'),$count('roles')];expect(min($counts)>0,$label.' actually reloads current grants');
    echo $label.': '.json_encode($counts)." user/role queries\n";
    return [$counts,$count('domains_domains')];
}
$queue=Livewire::actingAs($viewer)->test(ListOperations::class)->searchTable($held->domain);
$activity=Livewire::actingAs($viewer)->test(RequestsRelationManager::class,['ownerRecord'=>$held,'pageClass'=>ViewDomain::class]);
$queue->assertCountTableRecords(1);$activity->assertCountTableRecords(1);
[$queueOne]=measuredRefresh($queue,'Queue 1 row');
[$activityOne,$domainsOne]=measuredRefresh($activity,'Activity 1 row');
for($i=1;$i<=3;$i++){
    $copy=$op->replicate();$copy->operation_key='table-grants-'.$i;$copy->state=$i===1?'rejected':'uncertain';
    $copy->safe_code=$i===1?'transfer_provider_rejected':null;$copy->save();
}
$queue->call('$refresh')->assertCountTableRecords(4);$activity->call('$refresh')->assertCountTableRecords(4);
[$queueMany]=measuredRefresh($queue,'Queue 4 rows');
[$activityMany,$domainsMany]=measuredRefresh($activity,'Activity 4 rows');
same($queueOne,$queueMany,'Queue permission queries do not grow with row count');
same($activityOne,$activityMany,'Activity permission queries do not grow with row count');
same($domainsOne,$domainsMany,'Correction rows reuse the scoped activity owner');
expect($domainsMany<=1,'Only Livewire owner hydration may read the domain');
foreach([$queue,$activity] as $page){
    $page->assertSee('827.43')->assertSee('839.47')->assertSee('Transfer code: Stored')->assertSee('Correction unresolved');
    expect(!str_contains(json_encode($page->snapshot),'827.43')&&!str_contains(json_encode($page->snapshot),'StaffGrants'),'Render grants/costs never enter public component data');
}
foreach([[Staff::VIEW],[Staff::VIEW,Staff::COSTS],[Staff::VIEW,Staff::METADATA],['*']] as $permissions){
    DB::table('roles')->where('id',$viewer->role_id)->update(['permissions'=>json_encode($permissions)]);
    $cost=in_array('*',$permissions,true)||in_array(Staff::COSTS,$permissions,true);
    $metadata=in_array('*',$permissions,true)||in_array(Staff::METADATA,$permissions,true);
    foreach([$queue,$activity] as $page){
        $page->call('$refresh')->assertSuccessful();
        same($cost,str_contains($page->html(),'827.43'),'Costs follow fresh per-request grants');
        same($metadata,str_contains($page->html(),'Transfer code: Stored'),'Metadata follows fresh per-request grants');
    }
    $facts=RequestFacts::for($op);same($cost,isset($facts['cost']),'Default caller checks current costs');
    same($metadata,isset($facts['metadata']),'Default caller checks current metadata');
}
// The account summaries also resolve grants once before mapping their rows.
foreach(['for','reviews'] as $method){
    DB::enableQueryLog();DB::flushQueryLog();PaidUncertainty::$method($held);$queries=DB::getQueryLog();DB::disableQueryLog();
    same(1,count(array_filter($queries,fn($q)=>str_contains($q['query'],'from "users"'))),'Account '.$method.' reloads user once');
}
DB::table('roles')->where('id',$viewer->role_id)->update(['permissions'=>'[]']);
same([Staff::VIEW,Staff::COSTS,Staff::METADATA],$viewer->role->permissions,'Acting user retains a deliberately stale loaded role');
$queue->call('$refresh')->assertForbidden();$activity->call('$refresh')->assertForbidden();
foreach([fn()=>RequestFacts::for($op),fn()=>RequestFacts::review($op),fn()=>PaidUncertainty::summary($op),fn()=>PaidUncertainty::holdSummary($op)] as $read){
    try{$read();expect(false,'Default support caller requires fresh view');}
    catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Revoked default support read denied');}
}
echo "Native row-independent permission queries, scoped corrections and next-request revocation passed.\n";
$nativeSuccessful=true;
