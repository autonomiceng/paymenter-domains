<?php
// Enom 444f31c request-gates/provider-disposition/transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/transfer-dispatch-fixture.php';
// Simulate an auth-code commit between the dispatch snapshot and its locked runtime-row read.
\Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::install();
\Illuminate\Support\Facades\Queue::fake();
$waiting=$newTransfer(placeholderComFixtureName('dispatch-code-race-placeholder.com'),0,true,false);
$interleaved=false;
\Illuminate\Support\Facades\DB::listen(static function($query)use($waiting,&$interleaved):void {
    if($interleaved||!str_starts_with($query->sql,'select')||!str_contains($query->sql,'"domains_runtime_operations"')){return;}
    $interleaved=true;
    $current=$waiting->fresh();$current->payload=[...$current->payload,'epp'=>'SyntheticOnly'];
    \Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$waiting->id)->update(['state'=>'queued','payload'=>$current->getAttributes()['payload']]);
    \Illuminate\Support\Facades\DB::table('domains_runtime_operations')->where('operation_id',$waiting->id)->update(['due_at'=>0]);
});
(new \Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch($clock))->enqueue($waiting->id);
expect($interleaved,'Auth-code save interleaves before dispatch pacing is written');
same($clock->now()->getTimestamp()+60,(int)\Illuminate\Support\Facades\DB::table('domains_runtime_operations')->where('operation_id',$waiting->id)->value('due_at'),'Saved code retains ready-work pacing rather than missing-code backoff');
$assertTransferGuards(0);
$assertTransferDispatch(0);
$balanceRow=Illuminate\Support\Facades\DB::table('domains_registrar_balances')->where('server_id',$binding->serverId)->where('account_fingerprint',$binding->fingerprint)->sole();
same(['100.00','USD',null,'preflight'],[$balanceRow->amount,$balanceRow->currency,$balanceRow->last_error_code,$balanceRow->source],'Successful transfer preflight retains exact account balance');
same(2,$state->reads,'Transfer performs exactly two fresh preflight reader acquisitions');
foreach(array_slice($state->readers,-2) as $reader){same(1,count(array_filter($reader->calls,fn($call)=>$call==='balance')),'Each transfer observation reads balance exactly once');}

$state->mode='';
$op=$newTransfer(placeholderComFixtureName('rdap-unchecked-placeholder.com')); $makeTransfer(); $state->rdapUnknown=true; $r=$engine->execute($op->id);
same(true,$r->attempt['transfer_admission']['rdap_unchecked'],'Explicit unchecked provenance'); same(null,$r->attempt['transfer_admission']['base_expiry'],'Missing baseline never inferred');
// Enom TP_CreateOrder: the documented nested ID and an agreeing top-level ID are retained; disagreeing IDs retain none.
foreach (['nested'=>'7001','top'=>'7001','agree'=>'7001','conflict'=>null] as $shape=>$expected) {
    $state->mode='ok'; $state->rdapUnknown=false; $state->createShape=$shape; $state->calls=[]; $state->reads=0; $op=$newTransfer(placeholderComFixtureName('shape-'.$shape.'-placeholder.com')); $makeTransfer();
    $r=$engine->execute($op->id); same($expected,$r->attempt['evidence']['transferReference']??null,'Transfer order ID read from the '.$shape.' shape');
    same(null,$r->provider_order_id,'Transfer reference never becomes a billing order ID');
}
$state->createShape='nested';
echo "Enom transfer one-use dispatch, RDAP provenance and order references passed.\n"; $nativeSuccessful=true;
