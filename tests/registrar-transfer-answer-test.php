<?php
// Synthetic support closure on native paid transfers; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,EnomAnswers,PricingRule,RefundProof,StaffSettingsReview as Review,StaffSettingsWrites,TransferRejections};
app()->instance(Clock::class,$clock);app()->instance(AccountBindings::class,$bindings);
$staff=commerceUser();$permissions=['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.enom_answer','admin.domains.manage','admin.invoices.update'];
$role=App\Models\Role::create(['name'=>'Registrar answers','permissions'=>$permissions]);$staff->update(['role_id'=>$role->id]);
$settings=new StaffSettingsWrites($native,$sellers,$bindings);
$review=static fn($op)=>new Review($staff->id,$op->server_id,null,'enom_answer',Review::ticket($staff->id,$op->server_id,null,'enom_answer',Review::stamp($op->server_id,null)),'Record exact synthetic registrar answer');
$answer=static fn($op)=>['operation_id'=>$op->id,'attempt_hash'=>EnomAnswers::identity($op),'outcome'=>'transfer_rejected',
    'order_reference'=>'9001','transfer_status'=>'Transfer Rejected','charge'=>'99.00','registrar_refund'=>'93.05','final_confirmed'=>true,
    'case_reference'=>'synthetic-transfer-case','evidence'=>'Registrar checked this account, domain, original attempt time and exact order. The transfer cannot complete; the charge and refund are settled.','confirmed'=>true];
$rejected=static function($label,$charged=false)use($prepare,$observe,$reconcile){
    $op=$prepare($label);$observe->status='Transfer Rejected';$observe->fault=$charged?'rejectcharge':'reject';
    return $reconcile->run($op->id);
};
$op=$rejected('support');$attempt=$op->attempt;$writes=$state->calls;
expect(EnomAnswers::eligible($op,$clock->now()),'A rejected NameSilo transfer without an invented transfer ID can receive an audited answer');
Illuminate\Support\Facades\Auth::setUser($staff);
$action=\Paymenter\Extensions\Servers\Domains\Admin\Support\EnomAnswerAction::make($op,Review::stamp($op->server_id,null));
expect($action->isVisible(),'Request answer action is available for NameSilo');
$notice=\Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::notice($op);
expect(str_contains($notice['body']??'','Use '.$action->getLabel().'.'),'Request notice and account action use the same label');
same('Enter NameSilo’s answer',$action->getLabel(),'Request action names the registrar and follows main’s answer wording');
foreach(['evidence','case','confirmation','finality','status','pending','unknown','reference','stale','permission','amount','refund','not_processed'] as $fault){
    $input=$answer($op);
    if($fault==='evidence'){$input['evidence']='';}
    if($fault==='case'){$input['case_reference']='';}
    if($fault==='confirmation'){$input['confirmed']=false;}
    if($fault==='finality'){$input['final_confirmed']=false;}
    if($fault==='status'){unset($input['transfer_status']);}
    if($fault==='pending'){$input['transfer_status']='Pending at Registry';}
    if($fault==='unknown'){$input['transfer_status']='Transfer Timed Out';}
    if($fault==='reference'){$input['order_reference']='';}
    if($fault==='stale'){$input['attempt_hash']=str_repeat('0',64);}
    if($fault==='permission'){$role->update(['permissions'=>array_values(array_diff($permissions,['admin.invoices.update']))]);}
    if($fault==='amount'){$input['charge']='99.001';}
    if($fault==='refund'){$input['registrar_refund']='99.01';}
    if($fault==='not_processed'){$input['outcome']='not_processed';$input['order_reference']='';}
    commerceReject(fn()=>$settings->run($review($op),$input,$clock->now()));
    $role->update(['permissions'=>$permissions]);
    same(null,$op->fresh()->funding['refund']??null,'Invalid support evidence never returns money: '.$fault);
}
$foreign=$rejected('foreign');$bad=$answer($foreign);$bad['attempt_hash']=EnomAnswers::identity($op);
commerceReject(fn()=>$settings->run($review($foreign),$bad,$clock->now()));
$before=App\Models\Credit::where('user_id',$user->id)->sum('amount');
$settings->run($review($op),$answer($op),$clock->now());$op->refresh();
same(['rejected','transfer_rejected','cancelled'],[$op->state,$op->domain->status,$op->domain->service->status],'Audited finality closes the native transfer');
same($attempt,$op->attempt,'Sent attempt remains byte-for-byte intact');
same(['amount'=>'5.95','currency'=>'USD'],$op->cost['actual'],'Only the net failed fee is absorbed');
same(['99.00','93.05','Transfer Rejected','9001'],array_map(fn($k)=>$op->policy['enom_answer'][$k],['charge','registrar_refund','transfer_status','order_reference']),'Exact final amounts, status and order retained');
same($staff->id,$op->policy['enom_answer']['actor_id'],'Accountable staff identity retained');
same($op->amount,$op->funding['refund']['amount'],'Customer receives the full original payment');
same(PricingRule::units((string)$before,2)+PricingRule::units($op->amount,2),PricingRule::units((string)App\Models\Credit::where('user_id',$user->id)->sum('amount'),2),'Customer credit is exact and occurs once');
expect(TransferRejections::proved($op,$op->domain),'Saved finality reconstructs independently');
commerceReject(fn()=>$settings->run($review($op),$answer($op),$clock->now()));
same(null,$refunds->creditRejected($op->id),'Recovery never returns a second payment');
commerceReject(fn()=>RefundProof::requireUnsent($op,$op->domain));
commerceReject(fn()=>$op->update(['attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent']]));
commerceReject(fn()=>$op->fresh()->update(['policy'=>array_diff_key($op->policy,['enom_answer'=>true])]));
$writes=$state->calls;$engine->execute($op->id);same($writes,$state->calls,'Closure never repeats transferDomain');
// A charged rejection also needs the same explicit support finality and settled refund.
$charged=$rejected('support-charged',true);$settings->run($review($charged),$answer($charged),$clock->now());
expect(TransferRejections::proved($charged->fresh(),$charged->domain->fresh()),'A failed charged order closes through support rather than guessed empty history');
// Original-receipt returns use the existing idempotent gateway recovery after a lost reply.
require __DIR__.'/Support/money-receipt.php';
$gateway=App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Registrar receipt fixture','type'=>'gateway','enabled'=>true]);
$original=$rejected('support-receipt');
App\Models\InvoiceTransaction::where('invoice_id',$original->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-transfer-receipt']);
$lost=false;try{$settings->run($review($original),$answer($original),$clock->now());}catch(RuntimeException $e){same('Synthetic reply lost after acceptance',$e->getMessage(),'Expected lost gateway reply');$lost=true;}
expect($lost,'Lost return reply is exercised');$refunds->retryPending();$original->refresh();
same('original_payment',$original->funding['refund']['to'],'Gateway returns the original receipt');
same(1,MoneyReceiptGateway::$sends,'Lost reply recovery sends one return');
echo "Registrar transfer support closure preserves sent attempts and returns one customer payment.\n";$nativeSuccessful=true;
