<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AcquisitionPaymentReview,Staff};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionPaymentReviews,ObligationAlerts,ObservationSchema};
$rights=[Staff::VIEW,Staff::POLICY,AcquisitionPaymentReviews::PERMISSION,'admin.invoices.view'];
$reviewer=staffUser($rights);$reviews=new AcquisitionPaymentReviews($clock);app()->instance(AcquisitionPaymentReviews::class,$reviews);
$pending=static fn($op)=>AcquisitionPaymentReview::count($op->domain->fresh());
foreach(['register','transfer'] as $action){
    $op=$new(placeholderComFixtureName('late-review-'.$action.'-placeholder.com'),paid:false,action:$action);
    $retirement->retire($op->domain_id,$staff,$ticket($op),'Close unpaid order for review');
    InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);
    $retirement->inspect($op->domain_id);same(1,$pending($op),'Late payment needs review');
    $key=$reviews->ticket($reviewer,$op->domain_id);
    commerceReject(fn()=>$reviews->review($reviewer,$op->domain_id,$key,'Checked invoice and credit',false));
    commerceReject(fn()=>$reviews->review($reviewer,$op->domain_id,$key,"Bad\nreason",true));
    $other=staffUser($rights);commerceReject(fn()=>$reviews->review($other,$op->domain_id,$key,'Wrong actor refuses',true));
    foreach($rights as$permission){
        $reviewer->role->update(['permissions'=>array_values(array_diff($rights,[$permission]))]);
        commerceReject(fn()=>$reviews->review($reviewer,$op->domain_id,$key,'Revoked staff refuses',true));
    }
    $reviewer->role->update(['permissions'=>$rights]);
    DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
    commerceReject(fn()=>$reviews->review($reviewer,$op->domain_id,$key,'Changed invoice refuses',true));
    DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'cancelled']);
    // A payment or credit edit invalidates an already opened reconciliation review.
    InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'2.00','fee'=>'0.00','status'=>'failed']);
    commerceReject(fn()=>$reviews->review($reviewer,$op->domain_id,$key,'Changed payment refuses',true));
    $key=$reviews->ticket($reviewer,$op->domain_id);$user->credits()->updateOrCreate(['currency_code'=>'USD'],['amount'=>$action==='register'?'3.00':'6.00']);
    commerceReject(fn()=>$reviews->review($reviewer,$op->domain_id,$key,'Changed credit refuses',true));
    $retirement->inspect($op->domain_id);same(2,$pending($op),'New activity has its own alert');
    $key=$reviews->ticket($reviewer,$op->domain_id);$clock->instant=$clock->now()->modify('+901 seconds');
    commerceReject(fn()=>$reviews->review($reviewer,$op->domain_id,$key,'Expired review refuses',true));$clock->instant=$clock->now()->modify('-901 seconds');
    $money=$nativeMoney();$before=$op->fresh()->getRawOriginal();$key=$reviews->ticket($reviewer,$op->domain_id);
    commerceReject(fn()=>$reviews->review($reviewer,$op->domain_id,'invalid encrypted ticket','Invalid ticket refuses',true));
    $veto=true;$reviewDomain=$op->domain_id;
    Paymenter\Extensions\Servers\Domains\Models\Domain::updating(static function($d)use(&$veto,$reviewDomain){if($veto&&$d->id===$reviewDomain){return false;}});
    commerceReject(fn()=>$reviews->review($reviewer,$op->domain_id,$key,'Audit veto keeps alerts open',true));$veto=false;
    same(2,$pending($op),'Audit save veto cannot resolve alerts');
    same([], $op->domain->fresh()->renewal_policy['acquisition_payment_reviews']??[], 'No audit after failed save');
    $failAlert=true;DB::listen(static function($query)use(&$failAlert):void{if($failAlert&&str_starts_with(strtolower($query->sql),'update')&&str_contains($query->sql,'domains_obligation_alerts')){throw new LogicException('Synthetic alert write failure');}});
    commerceReject(fn()=>$reviews->review($reviewer,$op->domain_id,$key,'Alert failure rolls back audit',true));$failAlert=false;
    same(2,$pending($op),'Alert write failure rolls back resolutions');
    same([], $op->domain->fresh()->renewal_policy['acquisition_payment_reviews']??[], 'Alert failure rolls back audit');
    $clock->instant=$clock->now()->modify('+10 seconds');$retirement->inspect($op->domain_id);
    $reviews->review($reviewer,$op->domain_id,$key,'Reconciled invoice payments and account credit',true);
    same(0,$pending($op),'Acknowledgement clears every reviewed activity');
    same($money,$nativeMoney(),'Acknowledgement changes no native money or jobs');same($before,$op->fresh()->getRawOriginal(),'No registrar operation change');
    same(null,$op->domain->fresh()->reservation_name,'Acknowledgement cannot reclaim released name');
    $audit=$op->domain->fresh()->renewal_policy['acquisition_payment_reviews'][0];
    same([$reviewer->id,'Reconciled invoice payments and account credit',2],[$audit['actor_id'],$audit['reason'],count($audit['alert_ids'])],'Retained encrypted reconciliation audit');
    commerceReject(fn()=>$reviews->review($reviewer,$op->domain_id,$key,'A replay must refuse',true));
    $retirement->inspect($op->domain_id);same(0,$pending($op),'Repeated sweep keeps the reviewed activity resolved');
    InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'4.00','fee'=>'0.00','status'=>'processing']);
    $retirement->inspect($op->domain_id);same(1,$pending($op),'Later activity requires a new review');
    ObligationAlerts::record($op->domain->fresh(),'template_failed',$clock->now()->getTimestamp());
    ObservationSchema::install();staffPanel();Auth::login($reviewer);
    $viewer=staffUser([Staff::VIEW]);Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$op->domain_id])->assertActionDoesNotExist('review_late_payment');
    $page=Livewire::actingAs($reviewer)->test(ViewDomain::class,['record'=>$op->domain_id])->mountAction('review_late_payment')->assertActionMounted('review_late_payment');
    $beforeInvalidReason=$op->domain->fresh()->getRawOriginal();$pendingBefore=$pending($op);
    $page->setActionData(['confirmed'=>true,'reason'=>"Reviewed\u{2029}payment"])->callMountedAction()->assertHasActionErrors(['reason'=>'regex']);
    same($beforeInvalidReason,$op->domain->fresh()->getRawOriginal(),'Payment reason field error records no review');
    same($pendingBefore,$pending($op),'Payment reason field error keeps alerts unresolved');
    $page->setActionData(['confirmed'=>true,'reason'=>'Reconciled later payment and account credit'])->callMountedAction()->assertNotified('Payment review recorded');
    same(0,$pending($op),'Real modal invokes acknowledgement');
    same(1,DB::table('domains_obligation_alerts')->where('domain_id',$op->domain_id)->where('code','template_failed')->whereNull('resolved_at')->count(),'Unrelated notification alert stays open');
    Livewire::actingAs($reviewer)->test(ViewDomain::class,['record'=>$op->domain_id])->assertActionDoesNotExist('review_late_payment');
    $retirement->inspect($op->domain_id);same(0,$pending($op),'Second reviewed cycle stays resolved');
}
// An orphan alert can be displayed even when there is no request for a review ticket.
$orphan=$new(placeholderComFixtureName('late-review-orphan-placeholder.com'),paid:false);
$retirement->retire($orphan->domain_id,$staff,$ticket($orphan),'Close unpaid orphan review fixture');
ObligationAlerts::record($orphan->domain->fresh(),'acquisition_late_money',$clock->now()->getTimestamp(),null,'missing-operation');
same(1,$pending($orphan),'Orphan late-money alert needs staff attention');$orphanMoney=$nativeMoney();
$page=Livewire::actingAs($reviewer)->test(ViewDomain::class,['record'=>$orphan->domain_id])->mountAction('review_late_payment')->assertActionMounted('review_late_payment');
same('',$page->get('mountedActions.0.data.review_key'),'Unavailable review ticket leaves an empty key');
$page->setActionData(['confirmed'=>true,'reason'=>'Check orphan payment evidence'])->callMountedAction()->assertNotified("Couldn't record payment review");
same(1,$pending($orphan),'Refused orphan review retains the unresolved alert');
same([],$orphan->domain->fresh()->renewal_policy['acquisition_payment_reviews']??[],'Refused orphan review records no audit');
same($orphanMoney,$nativeMoney(),'Refused orphan review changes no financial rows');
// Real status-change hooks distinguish a new reactivation from another observation.
Paymenter\Extensions\Servers\Domains\Services\AcquisitionRetirement::boot();
foreach([false,true]as$paid){
$reactivated=$new(placeholderComFixtureName(($paid?'refunded':'unpaid').'-reactivation-review-placeholder.com'),paid:$paid);
if($paid){
    $refundStaff=staffUser(['admin.domains.manage','admin.invoices.update']);$reactivated->update(['state'=>'held','safe_code'=>'staff_review']);
    expect((new Paymenter\Extensions\Servers\Domains\Services\Refunds($clock))->refundHeld($refundStaff,$reactivated->id,'customer_cancelled')!==null,'Genuine account refund closes the paid fixture');
}else{$retirement->retire($reactivated->domain_id,$staff,$ticket($reactivated),'Close unpaid reactivation fixture');}
$service=App\Models\Service::findOrFail($reactivated->service_id);
$service->update(['status'=>'active']);same(1,$pending($reactivated),'First reactivation alerts');
$reviews->review($reviewer,$reactivated->domain_id,$reviews->ticket($reviewer,$reactivated->domain_id),'Reconciled first reactivation',true);
$retirement->inspect($reactivated->domain_id);same(0,$pending($reactivated),'Unchanged active service stays acknowledged');
$service->update(['status'=>'cancelled']);$service->update(['status'=>'active']);
same(1,$pending($reactivated),'Second reactivation with unchanged payments needs review');
$reviews->review($reviewer,$reactivated->domain_id,$reviews->ticket($reviewer,$reactivated->domain_id),'Reconciled second reactivation',true);
$retirement->inspect($reactivated->domain_id);same(0,$pending($reactivated),'Second reactivation stays acknowledged');
foreach(['alert','marker'] as $failure){
    $acknowledged=DB::table('domains_obligation_alerts')->where('domain_id',$reactivated->domain_id)->whereNotNull('resolved_at')->pluck('resolved_at','id')->all();
    $service->update(['status'=>'cancelled']);$fault=(object)['enabled'=>true];$domainId=(int)$reactivated->domain_id;
    Paymenter\Extensions\Servers\Domains\Models\Domain::updating(static function($d)use($fault,$domainId,$failure){if($fault->enabled&&$failure==='marker'&&(int)$d->id===$domainId&&$d->isDirty('renewal_policy')){return false;}});
    DB::listen(static function($query)use($fault,$failure):void{if($fault->enabled&&$failure==='alert'&&str_starts_with(strtolower($query->sql),'insert')&&str_contains($query->sql,'domains_obligation_alerts')){throw new RuntimeException('Synthetic reactivation observation failure');}});
    if($failure==='marker'){
        $marker=$reactivated->domain->fresh()->renewal_policy['late_payment_service_event'];
        commerceReject(fn()=>$service->update(['status'=>'active']));$service->refresh();
        same('cancelled',$service->status,'A refused occurrence write prevents native reactivation');
        same($marker,$reactivated->domain->fresh()->renewal_policy['late_payment_service_event'],'Refused reactivation retains its previous marker');
        $fault->enabled=false;$service->update(['status'=>'active']);
    }else{
        $service->update(['status'=>'active']);same('active',$service->fresh()->status,'Native activation commits before observation retry');
        same(0,$pending($reactivated),'Failed alert insertion rolls back the observation');
    }
    $fault->enabled=false;$retirement->inspect($reactivated->domain_id);
    same(1,$pending($reactivated),'A sweep recovers reactivation after '.$failure.' failure');
    same($acknowledged,DB::table('domains_obligation_alerts')->whereIn('id',array_keys($acknowledged))->pluck('resolved_at','id')->all(),'Earlier acknowledgements remain intact');
    $reviews->review($reviewer,$reactivated->domain_id,$reviews->ticket($reviewer,$reactivated->domain_id),'Reconciled recovered reactivation',true);
    $retirement->inspect($reactivated->domain_id);same(0,$pending($reactivated),'Recovered reactivation stays acknowledged');
}

}

echo "Late acquisition payment acknowledgement, current review, audit and repeated cycles passed.\n";$nativeSuccessful=true;
