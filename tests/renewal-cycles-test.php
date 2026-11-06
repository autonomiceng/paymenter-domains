<?php
// Enom d1392a8 complimentary/cancelled-unpaid cycles; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
$cycleFixtureGroup='unpaid';
require __DIR__.'/Support/renewal-cycle-fixture.php';
use App\Models\{InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, PricingRule, RenewalFunding};
[$op, $d, $b, $invoice] = $makeRenewal(placeholderComFixtureName('renew-replacement-placeholder.com'), false);
$clock->instant = new DateTimeImmutable('2027-09-27T12:00:00Z');
$invoice->update(['status' => 'cancelled']); commerceReject(fn () => $billing->invoice($d->id, $user));
$cycles->retireUnpaid($op->id); $retired = $op->fresh()->getRawOriginal(); $debt = $invoice->fresh()->getRawOriginal();
$replacementInvoice = $billing->invoice($d->id, $user); $replacement = Operation::where('invoice_id', $replacementInvoice->id)->sole();
same($op->operation_key.':replacement:1', $replacement->operation_key, 'Terminal unpaid debt receives distinct replacement key');
same($replacementInvoice->id, $billing->invoice($d->id, $user)->id, 'Exactly one current debt');
same($retired, $op->fresh()->getRawOriginal(), 'Original cycle evidence retained'); same($debt, $invoice->fresh()->getRawOriginal(), 'Original debt retained');
InvoiceTransaction::create(['invoice_id' => $replacementInvoice->id, 'amount' => $replacement->amount, 'fee' => '0.00', 'status' => 'succeeded']); $replacementInvoice->refresh()->update(['status'=>'paid']);
commerceReject(fn () => $cycles->retireUnpaid($replacement->id));
$clock->instant = new DateTimeImmutable('2027-10-18T11:59:00Z');
same('pending', $runRenewal($replacement, $b)->state, 'Replacement paid obligation executes once');
// Expiry cleanup uses the staff proof, regardless of owner preference or billing switches.
foreach (['manual_on','manual_off','scheduled_on','scheduled_off','transaction','sent','collecting','pending','uncertain'] as $case) {
    [$unpaid,$expiredDomain,,$unpaidInvoice]=$makeRenewal(placeholderComFixtureName('expiry-cancel-'.str_replace('_','-',$case).'-placeholder.com'),false,1,false,static function($domain)use($case,$product,$billing):void {
        if(str_starts_with($case,'scheduled_')){
            $product->server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
            $billing->invoice($domain->id);
        }
    });
    $expiredDomain->update(['renewal_policy'=>[...($expiredDomain->renewal_policy??[]),'owner_auto_renew'=>!str_ends_with($case,'_off')]]);
    if($case==='transaction'){InvoiceTransaction::create(['invoice_id'=>$unpaidInvoice->id,'amount'=>'0.01','fee'=>'0.00','status'=>'processing']);}
    if($case==='sent'){$unpaid->update(['started_at'=>$clock->now()]);}
    if($case==='collecting'){
        $funding=$unpaid->funding;$funding['auto_pay']=[...$funding['auto_pay'],'status'=>'uncertain','reason'=>null];
        $unpaid->funding=$funding;
        DB::table('domains_operations')->where('id',$unpaid->id)->update(['funding'=>$unpaid->getAttributes()['funding']]);
    }
    if(in_array($case,['pending','uncertain'],true)){$unpaid->update(['state'=>$case]);}
    $snapshot=static fn()=>serialize([$unpaid->fresh()->getRawOriginal(),$unpaidInvoice->fresh()->getRawOriginal()]);
    $clock->instant=new DateTimeImmutable('2027-10-17T23:59:59Z');$before=$snapshot();
    $cycles->expireUnpaid($expiredDomain->id);same($before,$snapshot(),'Day before expiry preserves '.$case);
    $clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');$calls=$state->calls;$writes=$state->writes;
    $cycles->expireUnpaid($expiredDomain->id);
    if(in_array($case,['manual_on','manual_off','scheduled_on','scheduled_off'],true)){
        same(['cancelled','rejected','renewal_cancelled_unpaid'],[$unpaidInvoice->fresh()->status,$unpaid->fresh()->state,$unpaid->fresh()->safe_code],'Expiry cancels unpaid '.$case);
        $audit=$unpaid->fresh()->policy['unpaid_retirement'];
        same(['Domain expired',$unpaidInvoice->id,['invoice'=>'pending','operation'=>'awaiting_payment'],['invoice'=>'cancelled','operation'=>'rejected']],
            [$audit['reason'],$audit['invoice_id'],$audit['before'],$audit['after']],'Cancellation retains financial audit');
    }else{same($before,$snapshot(),'Expiry skips '.$case);}
    $after=$snapshot();$cycles->expireUnpaid($expiredDomain->id);same($after,$snapshot(),'Repeated expiry cleanup is a no-op: '.$case);
    same([$calls,$writes],[$state->calls,$state->writes],'Cancellation makes no registrar calls');
    if($case==='manual_on'){
        $expiredDomain->update(['provider_expires_at'=>'2027-11-18']);$clock->instant=new DateTimeImmutable('2027-10-19T12:00:00Z');
        foreach([1,2] as $pass){
            $key=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\RenewalCycles::next(HoldingLocks::take($expiredDomain->fresh())));
            same('renew:'.$expiredDomain->id.':g'.$expiredDomain->authority_generation.':2027-11-18',$key,'Moved expiry permits a later cycle key: '.$pass);
        }
        $cycles->expireUnpaid($expiredDomain->id);same($after,$snapshot(),'Future expiry preserves cancelled history');
    }
}
same([],array_keys($registrations),'All independent acquisition scenarios ran');
echo "Native renewal cycle matrix passed\n"; $nativeSuccessful=true;
