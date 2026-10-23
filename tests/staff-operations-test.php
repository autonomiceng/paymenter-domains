<?php
// Native operations queue regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/staff-filter-lookalikes.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\{ListOperations,ViewOperation};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{PaidUncertainty,RefundCandidates,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Policies\OperationPolicy;
use Paymenter\Extensions\Servers\Domains\Services\RefundCandidates as Selection;
use Paymenter\Extensions\Servers\Domains\Services\AccountSpendingHolds;
staffPanel(); [$held,$active,$long]=staffData();
$viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer); $ops=Operation::orderBy('id')->get();
staffOperationFilterLookalikes($viewer, $active);
DB::table('domains_operations')->where('id',$ops[0]->id)->update(['funding_basis'=>'paid_invoice','state'=>'uncertain']);
DB::table('domains_operations')->where('id',$ops[2]->id)->update(['funding_basis'=>'paid_invoice','state'=>'rejected','safe_code'=>'transfer_provider_rejected']);
$ops=Operation::orderBy('id')->get();
$page=Livewire::actingAs($viewer)->test(ListOperations::class)->assertSuccessful()->assertCanSeeTableRecords($ops);
expect(!str_contains($page->html().json_encode($page->snapshot),'PRIVATE-'),'Queue never serializes private evidence');
foreach (['held'=>[], 'unresolved'=>[$ops[0]], 'failed_transfer'=>[$ops[2]], 'paid_uncertainty'=>[$ops[0],$ops[2]], 'credit_review'=>[]] as $name=>$yes) {
    echo 'Filter '.$name."\n"; $p=Livewire::actingAs($viewer)->test(ListOperations::class)->filterTable($name,true);
    $p->set('tableDeferredFilters',$p->get('tableFilters'))->call('applyTableFilters')->assertCanSeeTableRecords($yes)->assertCountTableRecords(count($yes));
}
Livewire::actingAs($viewer)->test(ViewOperation::class,['record'=>$ops[2]->id])->assertSuccessful()
    ->assertSee('Registrar refund is manual — request it from registrar support')->assertSee('No staff release or credit is available.');
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$held->id])->assertSee('Account spending holds')->assertSee('Request #'.$ops[2]->id);
expect(str_contains(implode(' ',PaidUncertainty::for($held)),'hours (since'),'Account-wide hold includes age');
same(null,RequestFacts::for($ops[1])['reason'],'Absent reason remains absent');
$completed=clone $ops[0];$completed->state='succeeded';same('Original send response was unknown; completion later confirmed',RequestFacts::review($completed)[0],'Completed readback explains original response uncertainty');
// Engine and staff share all-generation spending holds; paid terminal review is a separate fact.
same(null,PaidUncertainty::holdSummary($ops[2]),'Sent rejection is paid outcome review, not an in-flight spending hold');
$nonCurrent=$ops[0]->replicate();$nonCurrent->operation_key='non-current-generation-complimentary';$nonCurrent->funding_basis='complimentary';$nonCurrent->save();DB::table('domains_operations')->where('id',$nonCurrent->id)->update(['authority_generation'=>2]);$nonCurrent->refresh();
expect(PaidUncertainty::holdSummary($nonCurrent)!==null,'Complimentary in-flight request blocks spending too');
expect(str_contains(implode(' ',PaidUncertainty::for($held)),'Request #'.$nonCurrent->id),'Non-current generations remain visible in account hold facts');
foreach(Operation::all() as $candidate){same(AccountSpendingHolds::query(Operation::whereKey($candidate->id))->exists(),AccountSpendingHolds::matches($candidate),'Engine/projection hold parity');}
foreach(Operation::all() as $candidate){same(\Paymenter\Extensions\Servers\Domains\Admin\Support\OperationQueries::paidUncertainty(Operation::whereKey($candidate->id))->exists(),PaidUncertainty::summary($candidate)!==null,'Paid review SQL/projection parity');}

foreach (['register','renew','transfer','privacy_purchase','privacy_renew','contacts'] as $action) {
    foreach (['rejected','held','uncertain'] as $state) {
        $op=clone $ops[0]; $op->action=$action; $op->state=$state; $op->safe_code=null;
        foreach ([[],['refund'=>null],['refund'=>['to'=>'account_credit']]] as $funding) {
            same(Selection::matches($op,$funding),RefundCandidates::matches($op,$funding),'Shared admin/engine selector parity');
        }
    }
}
same(Selection::query(Operation::query())->pluck('id')->all(),RefundCandidates::query(Operation::query())->pluck('id')->all(),'SQL selector parity');
$policy=new OperationPolicy;
foreach ([[],[Staff::COSTS],[Staff::VIEW]] as $permissions) {
    $user=staffUser($permissions); Auth::setUser($user); $allowed=in_array(Staff::VIEW,$permissions,true);
    same($allowed,$policy->view($user,$ops[0]),'Operations default deny');
    $p=Livewire::actingAs($user)->test(ListOperations::class); $allowed?$p->assertSuccessful():$p->assertForbidden();
    expect(!$policy->update($user,$ops[0])&&!OperationResource::canEdit($ops[0]),'No generic row edit');
}
Auth::setUser($viewer);
DB::table('domains_operations')->where('id',$ops[1]->id)->update(['domain_id'=>$held->id]);
Livewire::actingAs($viewer)->test(ListOperations::class)->assertCanNotSeeTableRecords([$ops[1]]);
try { Livewire::actingAs($viewer)->test(ViewOperation::class,['record'=>$ops[1]->id]); expect(false,'Cross-bound ID refused'); }
catch (Illuminate\Database\Eloquent\ModelNotFoundException) {}
$open=Livewire::actingAs($viewer)->test(ViewOperation::class,['record'=>$ops[0]->id]);
$viewer->role->update(['permissions'=>[]]); $open->call('$refresh')->assertForbidden();
echo "Native operations queue, account holds, selector parity and authorization passed.\n";
$nativeSuccessful=true;
