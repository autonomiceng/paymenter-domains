<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ListOperations;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{PaidUncertainty, Staff};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
staffPanel();staffData();
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new \Paymenter\Extensions\Servers\Domains\Data\SystemClock);
$viewer=staffUser([Staff::VIEW]);Auth::setUser($viewer);
[$paid,,$second]=Operation::orderBy('id')->get()->all();
DB::table('domains_operations')->whereIn('id',[$paid->id,$second->id])->update(['state'=>'uncertain','safe_code'=>null,'funding_basis'=>'paid_invoice']);
$completed=$paid->replicate();$completed->operation_key='completed-paid-panel';
$completed->state='succeeded';$completed->safe_code=null;$completed->funding_basis='paid_invoice';$completed->save();
$free=[];
foreach(['auth_code_email','nameservers'] as $i=>$action){
    $op=$paid->replicate();$op->operation_key='free-panel-'.$i;$op->action=$action;$op->funding_basis='non_billable_management';
    $op->invoice_id=null;$op->amount='0.00';$op->state='uncertain';$op->save();$free[]=$op;
}
$hold=collect(PaidUncertainty::holds())->firstWhere('server_id',$paid->server_id);
same(2,$hold['count'],'Paid panel excludes both free management requests');
parse_str((string)parse_url($hold['url'],PHP_URL_QUERY),$params);
expect(isset($params['filters']),'Panel links to a filtered list');
$page=Livewire::actingAs($viewer)->test(ListOperations::class)->set('tableFilters',$params['filters']);
$page->set('tableDeferredFilters',$page->get('tableFilters'))->call('applyTableFilters')
    ->assertCanSeeTableRecords([$paid,$second])->assertCanNotSeeTableRecords([...$free,$completed])->assertCountTableRecords(2);
$filters=$params['filters'];$filters['status']['values']=['completed'];
$page=Livewire::actingAs($viewer)->test(ListOperations::class)->set('tableFilters',$filters);
$page->set('tableDeferredFilters',$page->get('tableFilters'))->call('applyTableFilters')
    ->assertCanSeeTableRecords([$completed])->assertCanNotSeeTableRecords([$paid,$second,...$free])->assertCountTableRecords(1);
DB::table('domains_operations')->whereIn('id',[$paid->id,$second->id])->update(['state'=>'succeeded']);
same([],PaidUncertainty::holds(),'Free uncertainty alone produces no paid panel');
$nativeSuccessful=true;
