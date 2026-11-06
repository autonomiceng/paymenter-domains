<?php
// Adapted from Enom 444f31c transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require __DIR__.'/transfer-input-fixture.php';
use App\Models\{Credit, InvoiceTransaction, Service};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, ContactRecovery, ContactSetup, InvoiceBinding, NativeDomainGuard, HolderPricing, PricingRule, Rdap, Refunds, TransferInputs, TransferPrecheck};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$baseline=$new(placeholderComFixtureName('transfer-paid-baseline-placeholder.com'));$inputs->paymentAccepted($baseline->id);
$paid=$baseline->fresh()->policy['paid_at'];
// Model a retained payment whose transition was not captured by the installed native hooks.
$missingTransition=static function(Operation $op): void {
    $policy=$op->fresh()->policy;unset($policy['paid_at']);
    // Preserve the current encrypted model cast while arranging a missing historical transition.
    $op->setAttribute('policy',$policy);
    DB::table('domains_operations')->where('id',$op->id)->update(['policy'=>$op->getAttributes()['policy']]);
    foreach(DB::table('audits')->where('auditable_type',App\Models\Invoice::class)->where('auditable_id',$op->invoice_id)->get() as $audit){
        $old=json_decode($audit->old_values??'{}',true);$new=json_decode($audit->new_values??'{}',true);
        if(($new['status']??null)==='paid'&&($old['status']??null)!=='paid'){DB::table('audits')->where('id',$audit->id)->delete();}
    }
};
