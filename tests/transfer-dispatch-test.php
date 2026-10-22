<?php
// Enom 444f31c request-gates/provider-disposition/transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/transfer-dispatch-fixture.php';
$assertTransferDispatch(0);
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
expect(Illuminate\Support\Facades\DB::table('domains_registrar_balances')->where('source','preflight')->exists(),'Existing balance path records without extra reads');
echo "Enom transfer one-use dispatch, RDAP provenance and order references passed.\n"; $nativeSuccessful=true;
