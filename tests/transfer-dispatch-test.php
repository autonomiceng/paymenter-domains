<?php
// Enom 444f31c request-gates/provider-disposition/transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-fixture.php';
\Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::install();
use Paymenter\Extensions\Servers\Domains\Services\{OperationTerminalProof, RefundProof};
foreach ([0,1] as $i) {
    foreach (['ok','301','302','lost','malformed'] as $mode) {
        $state->mode=$mode; $state->calls=[]; $state->reads=0; $before=$state->rdap; $op=$newTransfer('send-'.$i.'-'.$mode.'.com',$i); $makeTransfer($i);
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
}
$state->mode=''; $state->calls=[]; $op=$newTransfer('no-paid.com',0,false); $makeTransfer(); $engine->execute($op->id); same([],$state->calls,'Unpaid transfer never writes');
$op=$newTransfer('no-code.com',0,true,false); $makeTransfer(); same('awaiting_code',$engine->execute($op->id)->state,'Missing code retains editable input state'); same([],$state->calls,'Missing code never writes');
$slot='domains-write-slot:'.$binding->fingerprint; same(null,Illuminate\Support\Facades\Cache::get($slot),'Missing code reserves no slot');
foreach ([true,false] as $enabled) {
    $makeTransfer(0,new Paymenter\Extensions\Servers\Domains\Services\WriteFeatures(transfer:$enabled)); Illuminate\Support\Facades\Cache::put($slot,'other-dispatch',25);
    same('awaiting_code',$engine->execute($op->id)->state,'Busy slot or disabled feature cannot trap missing code');
    same('other-dispatch',Illuminate\Support\Facades\Cache::get($slot),'Other dispatch slot untouched');
    same('awaiting_code',$claims->holdUnclaimed($op->id,'write_rate_limited')->state,'Defensive unclaimed hold preserves editable input');
}
$request=Illuminate\Http\Request::create('/internal-transfer-input','POST'); $request->request->set('transfer_code',str_repeat('x',12)); $request->setUserResolver(fn()=>$user); $inputs->submitCode($request,$op->id);
expect(Paymenter\Extensions\Servers\Domains\Services\TransferInputs::validCode($op->fresh()->payload['epp']??null),'Later owner input remains editable');
$op=$newTransfer('disabled-transfer.com'); $makeTransfer(0,new Paymenter\Extensions\Servers\Domains\Services\WriteFeatures(registration:true));
same('held',$engine->execute($op->id)->state,'Default off transfer gate'); same([],$state->calls,'Registration permission grants no transfer permission');
foreach (['premium','unknown','funds','changed','reverse','rules','profile','rdap404'] as $fault) {
    $i=$fault==='profile'?1:0; $op=$newTransfer('refuse-'.$fault.'.com',$i); $makeTransfer($i); $state->calls=[]; $state->reads=0;
    $state->premium=$fault==='premium'?true:($fault==='unknown'?null:false); $state->funds=$fault==='funds'?'1.00':'100.00'; $state->mode=$fault==='profile'?'default':'';
    $state->rdap404=$fault==='rdap404';
    $state->during=static function($read)use($fault,$op,$state){
        if ($read!==2) { return; }
        if ($fault==='changed') { $state->available=false; }
        if ($fault==='reverse') { App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded']); }
        if ($fault==='rules') { App\Models\Server::find($op->server_id)->settings()->where('key','domains_transfer_endings')->update(['value'=>'net']); }
    };
    $r=$engine->execute($op->id); same('held',$r->state,'Changed admission held before seal'); same('absent',$r->attempt['seal'],'No send seal');
    same([],array_values(array_intersect($state->calls,['TP_CreateOrder','transferDomain'])),'Refused admission sends nothing');
    if ($fault==='rdap404') { same('transfer_not_registered',$r->safe_code,'Fresh domain-endpoint404 retains distinct unsent hold'); commerceReject(fn()=>OperationTerminalProof::encode($claims,$r->id)); }
    $state->rdap404=false; $state->during=null; $state->premium=false; $state->funds='100.00'; $state->available=true; $state->mode='';
    App\Models\Server::find($op->server_id)->settings()->where('key','domains_transfer_endings')->update(['value'=>'com']);
    if ($fault==='changed') {
        // The explicit pre-seal terminal encoder is the only new negative-proof/refund path.
        $terminal=OperationTerminalProof::encode($claims,$r->id); same('rejected',$terminal->state,'Proven local terminal');
        same(['amount'=>$op->amount,'currency'=>'USD'],$refunds->creditRejected($r->id),'Unsent terminal credited through RefundProof');
        same(null,$refunds->creditRejected($r->id),'Credit once');
    }
}
$op=$newTransfer('rdap-unchecked.com'); $makeTransfer(); $state->rdapUnknown=true; $r=$engine->execute($op->id);
same(true,$r->attempt['transfer_admission']['rdap_unchecked'],'Explicit unchecked provenance'); same(null,$r->attempt['transfer_admission']['base_expiry'],'Missing baseline never inferred');
// Enom TP_CreateOrder: the documented nested ID and an agreeing top-level ID are retained; disagreeing IDs retain none.
foreach (['nested'=>'7001','top'=>'7001','agree'=>'7001','conflict'=>null] as $shape=>$expected) {
    $state->mode='ok'; $state->rdapUnknown=false; $state->createShape=$shape; $state->calls=[]; $state->reads=0; $op=$newTransfer('shape-'.$shape.'.com'); $makeTransfer();
    $r=$engine->execute($op->id); same($expected,$r->attempt['evidence']['transferReference']??null,'Transfer order ID read from the '.$shape.' shape');
    same(null,$r->provider_order_id,'Transfer reference never becomes a billing order ID');
}
$state->createShape='nested';
expect(Illuminate\Support\Facades\DB::table('domains_registrar_balances')->where('source','preflight')->exists(),'Existing balance path records without extra reads');
echo "Dual transfer one-use dispatch, fresh admission, negative proof and uncertainty passed.\n"; $nativeSuccessful=true;
