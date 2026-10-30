<?php
// Hold is only offered when it has a way back. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use App\Models\InvoiceTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{OwnedDomains,OwnerPorts};
$open=fn($op)=>Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSuccessful();
$force=static fn(Operation $op,array $values)=>Operation::withoutEvents(static fn()=>$op->fresh()->update($values));

// Hold has no way back while the customer still owes payment or the auth code, so it is not offered then.
$unpaid=$new(placeholderComFixtureName('truth-unpaid-placeholder.com'),paid:false);
same('awaiting_payment',$unpaid->state,'Unpaid order waits on the customer');
$open($unpaid)->assertActionHidden('hold');
[$renewal]=$makeRenewal(placeholderComFixtureName('truth-renewal-placeholder.com'),paid:false);
same('awaiting_payment',$renewal->fresh()->state,'Unpaid renewal waits on the customer');
$open($renewal)->assertActionHidden('hold');
$paid=$new(placeholderComFixtureName('truth-paid-placeholder.com'));
same('awaiting_payment',$paid->state,'A paid order can still sit in this state until the worker runs');
$open($paid)->assertActionVisible('hold');
$code=$new(placeholderComFixtureName('truth-code-placeholder.com'),action:'transfer');
$force($code,['state'=>'awaiting_code','payload'=>array_diff_key($code->payload,['epp'=>true])]);
$open($code->fresh())->assertActionHidden('hold');
// A row already held while unpaid says when Release comes back, and it does come back.
$run($renewal->fresh(),'hold');
$open($renewal->fresh())->assertActionHidden('release')->assertSee('Customer has not paid the invoice.')->assertSee('You can release it once it is paid.');
DB::table('invoices')->where('id',$renewal->invoice_id)->update(['status'=>'paid']);
InvoiceTransaction::create(['invoice_id'=>$renewal->invoice_id,'amount'=>$renewal->amount,'fee'=>'0.00','status'=>'succeeded']);
$open($renewal->fresh())->assertActionVisible('release')->assertDontSee('You can release it once it is paid.');
// A transfer already held without its code: the customer can still add it, then Release comes back.
$paidAt=$clock->now()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
$force($code,['state'=>'held','safe_code'=>'staff_review_required','policy'=>[...($code->fresh()->policy??[]),'paid_at'=>$paidAt]]);
$open($code->fresh())->assertActionHidden('release')->assertSee('You can release it once they add it.');
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';
app('router')->getRoutes()->refreshNameLookups();
$owner=new OwnedDomains($clock,new OwnerPorts());
same(true,$owner->present($code->domain->fresh())->transfer['open'],'The customer form stays open');
$save=function(string $value)use($transfers,$user,$code):void {
    $request=Request::create('/internal-transfer-input','POST',['transfer_code'=>$value]);$request->setUserResolver(fn()=>$user);
    $transfers->submitCode($request,$code->id);
};
$save('LateCode');
same(['held','staff_review_required','LateCode'],[$code->fresh()->state,$code->fresh()->safe_code,$code->fresh()->payload['epp']],'The hold stays until staff release it');
$open($code->fresh())->assertActionVisible('release')->assertDontSee('You can release it once they add it.');
// Staff hold what is saved, so the customer can fill a gap but not change the code.
same(false,$owner->present($code->domain->fresh())->transfer['open'],'The form closes once the code is in');
try{$save('OtherCode');expect(false,'A saved code stays frozen under a staff hold');}catch(DomainException){}
same('LateCode',$code->fresh()->payload['epp'],'The held code is unchanged');
// Past the deadline nobody can add it, so the page offers the refund and promises nothing.
$late=$new(placeholderComFixtureName('truth-late-code-placeholder.com'),action:'transfer');
$force($late,['state'=>'held','safe_code'=>'staff_review_required','policy'=>[...($late->fresh()->policy??[]),'paid_at'=>$paidAt],'payload'=>array_diff_key($late->payload,['epp'=>true])]);
$clock->instant=$clock->now()->modify('+31 days');
$open($late->fresh())->assertSee('Auth code deadline passed.')->assertDontSee('once they add it')->assertActionVisible('refund');
echo "Hold passed.\n";

$nativeSuccessful=true;
