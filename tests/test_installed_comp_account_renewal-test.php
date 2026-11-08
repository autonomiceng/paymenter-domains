<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
expect(is_file(__DIR__.'/../Domains/Services/ComplimentaryAccounts.php'),'Account complimentary renewal must be implemented');
// Native checkout, invoice observers and retained financial locks require the real Paymenter fixture.
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{Invoice,Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,ComplimentaryCosts,ComplimentaryInvoices,HoldingLocks,RenewalFunding};
$staff=commerceUser();$role=Role::create(['name'=>'Complimentary funding','permissions'=>['admin.domains.manage','admin.domains.fund','admin.domains.costs']]);$staff->update(['role_id'=>$role->id]);
$enable=fn()=>ComplimentaryAccounts::set($user->id,$staff,true,'Staff account gift',$clock);
$disable=fn()=>ComplimentaryAccounts::set($user->id,$staff,false,'Gift ended',$clock);
[$op,$d,$b,$invoice]=$makeRenewal(placeholderComFixtureName('account-renew-placeholder.com'),false,1,false,$enable);
same(['complimentary','queued','0.00','paid',0.0,0],[$op->funding_basis,$op->state,$op->amount,$invoice->status,(float)$invoice->total,$invoice->transactions()->count()],'Renewal is complimentary with a paid zero receipt');
$before=Invoice::count();same($invoice->id,$billing->invoice($d->id)->id,'Scheduled billing returns the same funded term');same($before,Invoice::count(),'No second renewal invoice');
$disable();same('standard',$d->fresh()->price_mode,'Switch off changes new terms');
DB::transaction(function()use($op,$d){$l=HoldingLocks::take($d->fresh());same('complimentary',RenewalFunding::requireCurrent($op->fresh(),$l)['basis'],'Issued complimentary term survives switch off');});
$writes=$state->writes;same('pending',$runRenewal($op,$b)->state,'Already funded complimentary renewal sends');same($writes+1,$state->writes,'One registrar send');
[$engine,$writer,$completion]=$compose($b);$state->expiry='2028-10-18';$state->after='2028-10-18';$state->charge='8.00';
same('succeeded',$completion->run($op->id)->state,'Complimentary renewal completes from actual charge');
$invoice=$invoice->fresh();same(['8.00','-8.00'],$invoice->items->pluck('price')->all(),'Receipt shows registrar cost and exact complimentary offset');same(0.0,(float)$invoice->total,'Negative line totals to zero');
same('account-renew-placeholder.com renewal, 1 year',$invoice->items->first()->description,'Receipt names the domain, action and term');same('Complimentary',$invoice->items->last()->description,'Receipt labels the offset');
$completion->run($op->id);same(2,$invoice->items()->count(),'Repeated confirmation cannot duplicate receipt lines');
commerceReject(fn()=>$invoice->items()->orderByDesc('id')->first()->update(['price'=>'-7.99']));
commerceReject(fn()=>$invoice->fresh()->update(['status'=>'pending']));
commerceReject(fn()=>App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,'0.00'));
same(0,$invoice->transactions()->count(),'Zero receipts cannot accept a second funding or payment path');
$clock->instant=new DateTimeImmutable('2028-09-27T12:00:00Z');activeCommerceFacts($clock->now(),$clock->now()->modify('+30 days'),$ports);$observeRenewalCost($b);
$next=$billing->invoice($d->id,$user);same('pending',$next->status,'After switch off the next term asks for payment');expect((float)$next->total>0,'Next term charges standard pricing');
[$over,$heldDomain,$binding]=$makeRenewal(placeholderComFixtureName('account-cost-cap-placeholder.com'),false,1,false,$enable);
$state->price='8.01';$writes=$state->writes;same('held',$runRenewal($over,$binding)->state,'Registrar cost increase stops the complimentary send');same($writes,$state->writes,'Cost-cap stop pays nothing');
$review=$over->fresh()->policy['complimentary_cost_review'];
same(0,\Paymenter\Extensions\Servers\Domains\Services\WriteMoney::compare('8.00',$review['usual']),'The usual cost remains the original frozen amount');
same('Needs approval: registrar cost $8.0100 is above the usual $'.$review['usual'].'. Ask staff with domain cost, management and funding permissions to approve this cost. Nothing is charged until approval.',ComplimentaryCosts::message($over->fresh()),'Staff see the exact stored Enom quote and usual cost');
commerceReject(fn()=>ComplimentaryCosts::approve($over->id,$user,'8.01','Approved',$clock));
commerceReject(fn()=>ComplimentaryCosts::approve($over->id,$staff,'8.02','Stale quote',$clock));
ComplimentaryCosts::approve($over->id,$staff,$over->fresh()->policy['complimentary_cost_review']['amount'],'Reviewed registrar increase',$clock);
foreach(['staff_review_required','processing','transfer_setup_review_required']as$reason){
    $over->refresh();$over->update(['state'=>'held','safe_code'=>$reason]);$message=ComplimentaryCosts::message($over);
    $refused=false;try{ComplimentaryCosts::approve($over->id,$staff,'8.01','Stale cost review cannot release another hold',$clock);}catch(DomainException){$refused=true;}
    same([true,null,'held',$reason],[$refused,$message,$over->fresh()->state,$over->fresh()->safe_code],'A stale cost review cannot release an unrelated hold');
    same(1,DB::table('domains_complimentary_approvals')->where('operation_id',$over->id)->count(),'Unrelated holds cannot add a cost approval');
}
$over->update(['state'=>'queued','safe_code'=>null]);
// Advance the synthetic account slot without waiting for real cache time.
Illuminate\Support\Facades\Cache::forget('domains-write-slot:'.$binding->fingerprint);
$approved=$runRenewal($over,$binding);same('pending',$approved->state,'Approved exact cost can send through the same path: '.$approved->safe_code);same($writes+1,$state->writes,'Approval permits exactly one registrar send');
same(1,DB::table('domains_complimentary_approvals')->where('operation_id',$over->id)->count(),'Cost approval is audited once');
$disable();
echo "Account renewal, switch-off, actual receipt and cost approval passed\n";$nativeSuccessful=true;
