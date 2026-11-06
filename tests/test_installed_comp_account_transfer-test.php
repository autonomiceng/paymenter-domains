<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
expect(is_file(__DIR__.'/../Domains/Services/ComplimentaryAccounts.php'),'Account complimentary transfer must be implemented');
// Native checkout, invoice observers and retained financial locks require the real Paymenter fixture.
require __DIR__.'/Support/transfer-fixture.php';
use App\Models\Role;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,ComplimentaryCosts,PricingRule};
$staff=commerceUser();$staff->update(['role_id'=>Role::create(['name'=>'Account funding','permissions'=>['admin.domains.manage','admin.domains.fund']])->id]);
ComplimentaryAccounts::set($user->id,$staff,true,'Complimentary transfers',$clock);
foreach([0,1] as $i){
    $state->calls=[];$op=$newTransfer(placeholderComFixtureName('comp-transfer-'.$i.'-placeholder.com'),$i,false);
    same('complimentary',$op->funding_basis,'Transfer admitted as complimentary for registrar '.$i);same('0.00',$op->amount,'Transfer customer pays zero');
    same('paid',App\Models\Invoice::findOrFail($op->invoice_id)->status,'Transfer has its own paid receipt');same(0,App\Models\Invoice::findOrFail($op->invoice_id)->transactions()->count(),'Transfer creates no native payment');
    $state->quote=$op->cost['estimated'];$makeTransfer($i);$sent=$engine->execute($op->id);
    expect(in_array($sent->state,['pending','uncertain'],true),'Complimentary transfer sends through the guarded engine: '.$sent->safe_code);
    same(1,count(array_intersect($state->calls,['TP_CreateOrder','transferDomain'])),'Exactly one transfer send');
    $engine->execute($op->id);same(1,count(array_intersect($state->calls,['TP_CreateOrder','transferDomain'])),'Paid receipt never replays a transfer');
    $state->calls=[];$op=$newTransfer(placeholderComFixtureName('comp-transfer-cap-'.$i.'-placeholder.com'),$i,false);
    $state->quote=PricingRule::decimal(PricingRule::units($op->cost['estimated'])+100,4);$makeTransfer($i);
    $held=$engine->execute($op->id);same('held',$held->state,'Transfer cost increase holds before sending');
    expect(ComplimentaryCosts::message($held)!==null,'Transfer cost increase gives staff an approval');same(0,count(array_intersect($state->calls,['TP_CreateOrder','transferDomain'])),'Held transfer costs nothing');
    ComplimentaryCosts::approve($held->id,$staff,$held->policy['complimentary_cost_review']['amount'],'Approved exact transfer cost',$clock);
    $makeTransfer($i);$sent=$engine->execute($op->id);expect(in_array($sent->state,['pending','uncertain'],true),'Approved transfer can send: '.$sent->safe_code);
    same(1,count(array_intersect($state->calls,['TP_CreateOrder','transferDomain'])),'Approval grants exactly one send');
}
echo "Complimentary transfer admission, cost caps and bounded approvals for both registrars passed\n";$nativeSuccessful=true;
