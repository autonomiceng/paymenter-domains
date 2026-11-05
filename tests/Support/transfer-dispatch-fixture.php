<?php
// Enom 444f31c request-gates/provider-disposition/transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require __DIR__.'/transfer-fixture.php';
\Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::install();
use Paymenter\Extensions\Servers\Domains\Services\RefundProof;
$assertTransferDispatch = static function (int $i) use ($state,$newTransfer,$makeTransfer,&$engine,$refunds,$user,&$bridge,&$binding,$clock): void {
    foreach (['ok','301','302','lost','malformed'] as $mode) {
        $state->mode=$mode; $state->calls=[]; $state->reads=0; $before=$state->rdap; $op=$newTransfer(placeholderComFixtureName('send-'.$i.'-'.$mode.'-placeholder.com'),$i); $makeTransfer($i);
        $r=$engine->execute($op->id); same('uncertain',$r->state,'Ack remains financially unresolved: '.$r->safe_code);
        same(2,$state->reads,'Fresh admission repeated immediately before send'); expect($state->rdap-$before>=2,'Two fresh RDAP observations');
        $writes=array_values(array_intersect($state->calls,['TP_CreateOrder','transferDomain'])); same([$i===0?'TP_CreateOrder':'transferDomain'],$writes,'Exactly one transfer write');
        same(false,$r->attempt['transfer_admission']['premium'],'Independent ordinary evidence sealed');
        same('2027-01-01',$r->attempt['transfer_admission']['base_expiry'],'Original expiry frozen before send');
        same(false,$r->attempt['transfer_admission']['rdap_unchecked'],'Checked provenance retained');
        same(null,$r->cost['actual']??null,'Ack amount never actual cost'); same(null,$r->domain->provider_expires_at,'No premature billing anchor');
        $calls=$state->calls; $engine->execute($op->id); same($calls,$state->calls,'No replay');
        commerceReject(fn()=>RefundProof::requireUnsent($r,$r->domain)); commerceReject(fn()=>$refunds->refundHeld($user,$r->id,'staff_review'));
        commerceReject(fn()=>$r->update(['attempt'=>[...$r->attempt,'transfer_admission'=>null]])); $r->refresh();
        commerceReject(fn()=>$r->update(['payload'=>[...$r->payload,'epp'=>null]]));
        commerceReject(fn()=>$bridge->transferIn(new Paymenter\Extensions\Servers\Domains\Data\AdmittedOperation($binding,$state->query,$op->operation_key,$r->attempt['claim_key'],1,'operation:'.$r->id.':seal',Paymenter\Extensions\Servers\Domains\Services\OperationClaims::payloadDigest($r),new Paymenter\Extensions\Servers\Domains\Data\Money('7.00','USD')),
            new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,$clock->now()->modify('+25 seconds'),new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(1),$clock)));
    }
};

// Paired native authority refusals: real funding/reservation rows and synthetic provider reads.
$assertTransferGuards=static function(int $i)use($state,$newTransfer,$makeTransfer,&$engine,$sellers,$clock):void {
    // Reuse one native paid fixture; each refusal starts from the same unsent operation.
    // Funding reversal is last and remains retained, as native financial evidence requires.
    $op=$newTransfer(placeholderComFixtureName('guard-'.$i.'-placeholder.com'),$i);
    $unsent=$op->getRawOriginal();
    foreach(['deployment','account','orders','reservation','balance','cost','funding'] as $fault){
        $state->calls=[];$state->reads=0;$state->mode='';$state->missingCost=false;$state->quote='99.00';$state->funds='100.00';
        $op->refresh();
        if($fault==='deployment'){
            $policy=\Illuminate\Support\Facades\DB::table('domains_sales_policies')->where('tld','com')->first();
            \Illuminate\Support\Facades\DB::table('domains_sales_policies')->where('id',$policy->id)->update(['transfer_enabled'=>false]);
            try{commerceReject(fn()=>$sellers->selection('com','transfer',1,$clock->now()));}
            finally{\Illuminate\Support\Facades\DB::table('domains_sales_policies')->where('id',$policy->id)->update(['transfer_enabled'=>$policy->transfer_enabled]);}
        }
        $makeTransfer($i,$fault==='deployment'?new \Paymenter\Extensions\Servers\Domains\Services\WriteFeatures:null);
        $accounts=\Illuminate\Support\Facades\DB::table('domains_accounts')->where('server_id',$op->server_id);
        $before=$accounts->first();$reservation=$op->domain->reservation_name;
        if($fault==='account'){$accounts->update(['account_fingerprint'=>str_repeat('0',64)]);}
        if($fault==='orders'){$accounts->update(['processing_enabled'=>false]);}
        if($fault==='funding'){App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded']);}
        if($fault==='reservation'){\Illuminate\Support\Facades\DB::table('domains_domains')->where('id',$op->domain_id)->update(['reservation_name'=>null]);}
        if($fault==='balance'){$state->funds='1.00';}
        if($fault==='cost'){$state->missingCost=true;}
        try{
            $r=$engine->execute($op->id);
            $reason=match($fault){'deployment'=>'write_disabled','account'=>$i===1?'namesilo_account_guard':'claim_authority_invalid','orders'=>'processing','balance'=>'registrar_balance_insufficient','cost'=>'registrar_price_missing',default=>'claim_authority_invalid'};
            same($reason,$r->safe_code,'Specific guard refuses: '.$i.' '.$fault.' / '.$r->safe_code);
            expect(!isset($r->attempt['dispatch_attempted_at']),'Guard refuses before send: '.$i.' '.$fault);
            same([],array_values(array_intersect($state->calls,['TP_CreateOrder','transferDomain'])),'Guard grants no transfer write: '.$i.' '.$fault);
            expect($r->state!=='succeeded','Guard cannot complete a refused transfer');
        }finally{
            $accounts->update(['account_fingerprint'=>$before->account_fingerprint,'processing_enabled'=>$before->processing_enabled]);
            \Illuminate\Support\Facades\DB::table('domains_domains')->where('id',$op->domain_id)->update(['reservation_name'=>$reservation]);
            if($fault!=='funding'){\Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$op->id)->update($unsent);}
            $state->missingCost=false;$state->quote='99.00';$state->funds='100.00';
        }
    }
    \Illuminate\Support\Facades\DB::table('domains_read_budgets')->where('server_id',$op->server_id)->delete();
};
