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
        $r=$engine->execute($op->id); same('uncertain',$r->state,'Ack remains financially unresolved');
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
        commerceReject(fn()=>$bridge->transferIn(new Paymenter\Extensions\Servers\Domains\Data\AdmittedOperation($binding,$state->query,$op->operation_key,$r->attempt['claim_key'],1,'operation:'.$r->id.':seal',Paymenter\Extensions\Servers\Domains\Services\OperationClaims::payloadDigest($r),new Paymenter\Extensions\Servers\Domains\Data\Money('99.00','USD')),
            new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,$clock->now()->modify('+25 seconds'),new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(1),$clock)));
    }
};
