<?php
// Native checkout, locked recovery and notification planning need real Paymenter invoice/service rows.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use App\Models\{Credit,Invoice,InvoiceTransaction,Role,Service};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain};
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,FundsWaits,HoldingLocks,OwnerPorts,OwnerSetup,StaffOperationReview,StaffOperations,TransferNotifications};
(new Paymenter\Extensions\Servers\Domains\Services\NativeDomainGuard(new Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices),$clock))->boot();
Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
$staff=commerceUser();$staff->update(['role_id'=>Role::create(['name'=>'Complimentary recovery','permissions'=>['admin.domains.view','admin.domains.manage','admin.domains.fund','admin.domains.operations.release']])->id]);
ComplimentaryAccounts::set($user->id,$staff,true,'Audited recovery account',$clock);
config(['domains.enom_writes.registration'=>true,'domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true]);
$ports=new OwnerPorts(fn($b)=>new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management($b,fn()=>throw new RuntimeException('Recovery must not call the registrar')));
$setup=new OwnerSetup($clock,$ports);$staffWrites=new StaffOperations($clock);$failures=[];
$check=static function(string $name,Closure $call)use(&$failures):void {
    try{$call();echo "PASS recovery: ".$name."\n";}catch(Throwable $e){$failures[]=$name.': '.get_class($e).' '.$e->getMessage();}
};
$newRegistration=static function(string $name,string $action='register')use($user,$native,$tlds):Paymenter\Extensions\Servers\Domains\Models\Operation {
    $cart=commerceCart($user);commerceAdd($cart,$native->product($tlds[0],$action),$name);commerceCheckout($user,$cart)->assertHasNoErrors();
    return Domain::where('domain',$name)->sole()->operations()->sole();
};
$op=$newRegistration(placeholderComFixtureName('comp-recovery-placeholder.com'));
$op->update(['state'=>'held','safe_code'=>'nameservers_required']);
$money=static fn()=>serialize([DB::table('invoices')->get(),DB::table('invoice_items')->get(),DB::table('invoice_transactions')->get(),DB::table('credits')->get()]);
$before=$money();
$check('owner saves held complimentary registration setup',function()use($setup,$user,$op):void {
    $setup->save($user,$op->domain_id,['nameservers'=>['ns1.example.com','ns2.example.com'],'privacy'=>false]);
    same(['ns1.example.com','ns2.example.com'],$op->fresh()->payload['nameservers'],'Owner setup persists the desired nameservers');
    same($user->id,$op->fresh()->policy['owner_setup']['actor'],'Setup retains the owner audit');
});
$check('staff releases and engine re-admits the same funded term',function()use($staff,$staffWrites,$op,$clock,$claims):void {
    Auth::login($staff);$current=$op->fresh();
    $ticket=StaffOperationReview::ticket($staff->id,$op->domain_id,$op->id,'release',StaffOperationReview::stamp($current,$current->domain),$clock);
    $staffWrites->run($staff,$op->domain_id,$op->id,'release',$ticket,'Reviewed corrected setup');
    same('queued',$op->fresh()->state,'Staff can release the complimentary hold');
    same('release',$op->fresh()->policy['staff_actions'][0]['action'],'Release retains staff audit');
    commerceReject(fn()=>$staffWrites->run($staff,$op->domain_id,$op->id,'release',$ticket,'Replay refused'));
    $claim=$claims->claim($op->id);expect($claim!==null,'The normal engine re-admits the existing complimentary term');
    same('complimentary',$claim[0]->funding['claim']['basis'],'Re-admission retains the audited complimentary basis');
    expect(!isset($claim[0]->attempt['execution']),'Re-admission grants no provider send');
    $claims->finish($claim[0],holdReason:'registrar_balance_insufficient');
});
same($before,$money(),'Setup/release/re-admission do not collect money or change the receipt');
$transfer=$newRegistration(placeholderComFixtureName('comp-code-notice-placeholder.com'),'transfer');
$check('complimentary transfer receives missing-code and deadline notices',function()use($transfer,$transfers,$clock):void {
    same($transfer->created_at->utc()->format(DATE_ATOM),$transfer->policy['paid_at']??null,'Receipt issuance freezes the transfer input clock');
    same('awaiting_code',$transfer->state,'Missing-code complimentary transfers await their input immediately');
    same([$transfer->id],\Paymenter\Extensions\Servers\Domains\Services\RuntimeCandidates::transfer(\Paymenter\Extensions\Servers\Domains\Models\Operation::whereKey($transfer->id))->pluck('id')->all(),'Native runtime discovers the complimentary transfer');
    app()->instance(\Paymenter\Extensions\Servers\Domains\Services\TransferInputs::class,$transfers);
    app()->instance(\Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch::class,new \Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch($clock));
    $runtime=new \Paymenter\Extensions\Servers\Domains\Services\RuntimeWork($clock,new \Paymenter\Extensions\Servers\Domains\Services\RuntimePorts);
    $runtime->service($transfer->service_id);$transfer->refresh();
    $fixed=$transfer->policy['paid_at'];$clock->instant=$clock->now()->modify('+1 minute');$runtime->service($transfer->service_id);
    same($fixed,$transfer->fresh()->policy['paid_at'],'Repeated runtime recovery cannot reset the fixed input deadline');
    foreach([['+1 day','domains_transfer_code_paid'],['+24 days','domains_transfer_code_reminder']] as [$offset,$template]){
        $plan=DB::transaction(function()use($transfer,$clock,$offset){$alerts=[];return TransferNotifications::plan(HoldingLocks::take($transfer->domain),$clock->now()->modify($offset),$alerts);});
        same($template,$plan['template']??null,'Missing-code mail is planned at '.$offset);
        same('$0.00',$plan['data']['amount'],'Notice never asks the complimentary customer to pay');
    }
});
$wait=$op->fresh();
$wait->update(['state'=>'held','safe_code'=>'registrar_balance_insufficient','policy'=>[...$wait->policy,'funds_wait_at'=>$clock->now()->format(DATE_ATOM)]]);
$check('elapsed complimentary funds wait closes without payment or refund',function()use($wait,$clock,$money):void {
    $clock->instant=$clock->now()->modify('+3 days');
    $before=$money();$account=Account::where('server_id',$wait->server_id)->sole();
    FundsWaits::sweep($account,$clock->now());
    same('rejected',$wait->fresh()->state,'Elapsed unsent complimentary acquisition closes');
    same('registration_rejected',$wait->domain->fresh()->status,'The domain shows the terminal outcome');
    same('cancelled',Service::findOrFail($wait->service_id)->status,'Funds closure cancels the native service in its transaction');
    expect(Paymenter\Extensions\Servers\Domains\Services\OperationTerminalProof::safe($wait->fresh(),$wait->domain->fresh()),'Closure retains negative proof');
    same(0,DB::table('domains_obligation_alerts')->where('operation_id',$wait->id)->whereNull('resolved_at')->whereIn('code',['funds_wait_review','refund_repair'])->count(),'Complimentary closure never asks for nonexistent payment/refund repair');
    FundsWaits::sweep($account,$clock->now());same($before,$money(),'Repeated sweep changes no invoice, transaction or credit');
    same('paid',Invoice::findOrFail($wait->invoice_id)->status,'The zero receipt stays Paid');
});
$check('complimentary missing-code transfer closes exactly at its cutoff without credit',function()use($transfer,$transfers,$clock,$money):void {
    $deadline=Paymenter\Extensions\Servers\Domains\Services\TransferInputs::deadline($transfer->fresh(),$clock->now());
    expect($deadline!==null,'Issued complimentary transfer has a verified cutoff');
    $before=$money();$open=$transfer->fresh()->getRawOriginal();
    $clock->instant=$deadline->modify('-1 second');
    same(null,$transfers->expireMissingCode($transfer->id),'No early complimentary timeout');
    same($open,$transfer->fresh()->getRawOriginal(),'Early check preserves the open transfer');
    $clock->instant=$deadline;
    same(null,$transfers->expireMissingCode($transfer->id),'Complimentary timeout has no customer credit');
    $closed=$transfer->fresh();
    same('rejected',$closed->state,'Transfer closes at the exact cutoff');
    same('transfer_code_timeout',$closed->safe_code,'Closure retains the timeout reason');
    same('transfer_rejected',$closed->domain->status,'Domain shows the rejected transfer');
    same('cancelled',Service::findOrFail($closed->service_id)->status,'Native service is cancelled');
    expect(Paymenter\Extensions\Servers\Domains\Services\RefundProof::transferTimeout($closed,$closed->domain),'Closure retains definitely-unsent timeout proof');
    same($closed->domain->domain,$closed->domain->reservation_name,'Closure retains the name reservation');
    same($before,$money(),'Timeout changes no invoice, transaction or credit');
    same('paid',Invoice::findOrFail($closed->invoice_id)->status,'Zero receipt stays Paid');
    expect(!isset($closed->funding['refund']),'No invented refund audit');
    same(0,DB::table('domains_obligation_alerts')->where('operation_id',$closed->id)->whereNull('resolved_at')->where('code','refund_repair')->count(),'No nonexistent payment repair');
    $terminal=$closed->getRawOriginal();
    same(null,$transfers->expireMissingCode($closed->id),'Repeated timeout adds no credit');
    same($terminal,$closed->fresh()->getRawOriginal(),'Repeated timeout preserves terminal evidence');
    same($before,$money(),'Repeated timeout preserves native money history');
});
same(0,InvoiceTransaction::count(),'All recovery paths leave complimentary receipts without payments');same(0,Credit::count(),'No refund credit is invented');
same(0,$writer->calls,'No recovery action sends to the registrar');
$check('ordinary paid transfer timeout still adds its exact account credit once',function()use($clock,$now,$user,$staff,$newRegistration,$transfers,$money):void {
    $clock->instant=$now;ComplimentaryAccounts::set($user->id,$staff,false,'Return future terms to paid pricing',$clock);
    $paid=$newRegistration(placeholderComFixtureName('paid-code-timeout-placeholder.com'),'transfer');
    same('paid_invoice',$paid->funding_basis,'Switch-off admits an ordinary paid term');
    DB::table('invoices')->where('id',$paid->invoice_id)->update(['status'=>'paid']);
    InvoiceTransaction::create(['invoice_id'=>$paid->invoice_id,'amount'=>$paid->amount,'fee'=>'0.00','status'=>'succeeded']);
    $transfers->paymentAccepted($paid->id);$clock->instant=$now->modify('+30 days');
    same(['amount'=>$paid->amount,'currency'=>'USD'],$transfers->expireMissingCode($paid->id),'Paid timeout returns the actual paid amount');
    same('cancelled',Service::findOrFail($paid->service_id)->status,'Paid timeout still cancels its native service');
    same('transfer_code_timeout',$paid->fresh()->funding['refund']['reason'],'Paid credit retains its timeout audit');
    same(1,Credit::count(),'Only the paid transfer receives account credit');
    same(Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($paid->amount),Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)Credit::sole()->amount),'Credit equals the paid transfer line');
    $closed=$money();same(null,$transfers->expireMissingCode($paid->id),'Paid timeout cannot credit twice');
    same($closed,$money(),'Paid replay preserves native money history');
});
expect($failures===[],implode("\n",$failures));
echo "Complimentary held setup, audited release, funds cutoff and transfer notices passed\n";$nativeSuccessful=true;
