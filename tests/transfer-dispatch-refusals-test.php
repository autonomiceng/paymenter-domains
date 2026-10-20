<?php
// Enom 444f31c request-gates/provider-disposition/transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-dispatch-fixture.php';
use Paymenter\Extensions\Servers\Domains\Services\OperationTerminalProof;
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
echo "Transfer dispatch input, feature and pre-seal refusal proofs passed.\n"; $nativeSuccessful=true;
