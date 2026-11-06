<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{RenewalAdmission,RenewalAcceptance};
config(['domains.enom_writes.renewal'=>false]);
// Reconstruct retained unreviewed two-year paid debt through model encryption
// so the execution guard still proves that retained longer debt cannot gain send authority.
[$termOp,$termDomain,$termBinding,$termInvoice]=$makeRenewal(placeholderComFixtureName('renew-test-annual-only-placeholder.com'),false);
$termAmount=Paymenter\Extensions\Servers\Domains\Services\PricingRule::total($termOp->amount,2);
$termPolicy=$termOp->policy;$termPolicy['renewal']['term']=2;$termPolicy['renewal']['cost_cap']=['amount'=>'16.0000','currency'=>'USD'];
$termOp->policy=$termPolicy;
DB::table('domains_operations')->where('id',$termOp->id)->update(['term'=>2,'amount'=>$termAmount,
    'expected_expires_at'=>'2029-10-18','policy'=>$termOp->getAttributes()['policy']]);
DB::table('invoice_items')->where('id',$termOp->invoice_item_id)->update(['price'=>$termAmount]);
App\Helpers\ExtensionHelper::addPayment($termInvoice->id,null,$termAmount);
$termOp=$termOp->fresh();same(2,(int)$termOp->term,'Retained fixture has a real two-year debt');
[$termEngine,$termWriter]=$compose($termBinding);
// Full native debt is required to reach the spending boundary; a value-only fixture cannot prove no reads/sends.
$termRequest=new \Paymenter\Extensions\Servers\Domains\Data\RenewalRequest($termDomain->id,$termDomain->authority_generation,
    $termDomain->confirmed_revision,$termOp->base_expires_at->format('Y-m-d'),$termDomain->provider_domain_id,2,$termOp->operation_key);
$termAdmission=new RenewalAdmission($authority,$renewBindings,new RenewalAcceptance($termBinding,true,true,25,true),$clock);
same('renewal_term_refused',$termAdmission->check($termRequest)->code->value,'Opt-out explicit runtime refuses retained longer debt');
same([],$state->calls,'Opt-out longer debt never reaches provider reads');

echo "Retained term opt-out guard: ok\n";
$nativeSuccessful=true;
