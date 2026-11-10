<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\Service;
use Illuminate\Support\Facades\DB;
$op=$new(placeholderComFixtureName('cancel-unverified-placeholder.com'));
// A historical failed attempt is not currently processing and is not proof of a resolved result.
DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'rejected','started_at'=>now(),'safe_code'=>'staff_review_required']);
$before=$nativeMoney();
foreach(['cancelled','suspended'] as $status){
    try { Service::findOrFail($op->service_id)->update(['status'=>$status]); expect(false,'Unverified acquisition must stay protected'); }
    catch(\Illuminate\Validation\ValidationException $error){
        same("The registration or transfer result has not been verified. Check the domain's requests on the Domains page before cancelling or suspending it.",$error->errors()['status'][0],'A stopped request names the missing proof and the next step');
    }
    same('pending',Service::findOrFail($op->service_id)->status,'Refusal keeps the service');
    same($before,$nativeMoney(),'Refusal preserves billing and queued work');
}
staffPanel();
Illuminate\Support\Facades\Auth::login($staff);
$op->domain->update(['status'=>'active']);
Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($op->domain->fresh(),'below_cost',time());
Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($op->domain->fresh(),'operation_review',time(),$op->id);
$domain=$op->domain->fresh();
$facts=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($domain);
$head=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::head($domain,Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($domain),$facts);
// Request review leads over a cost alert, so the head owns the review step and its one request link.
$step=(string)$head['step'];
$text=html_entity_decode(strip_tags($step),ENT_QUOTES);
expect(str_contains($text,'Request failed. Check request #'.$op->id.'.'),'Domain head names the failed request outcome');
same('Expiry missing. Request failed. Check request #'.$op->id.'.',$text,'Domain head names the rejected request to check before the cost alert: '.$step);
expect(str_contains($step,'>Check request #'.$op->id.'</a>'),'Domain head links to the rejected request');
same(1,substr_count($step,'Check request #'.$op->id),'Domain head names this request exactly once');
expect(!str_contains((string)$facts['Registrar']['sub'],'Check request'),'Registrar row leaves the request to the head');
foreach ([['rejected','management_retired'],['held','staff_hold'],['uncertain','management_unconfirmed']] as [$state,$code]) {
    DB::table('domains_operations')->where('id',$op->id)->update(['state'=>$state,'safe_code'=>$code]);
    $status=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($domain->fresh());
    expect(!str_contains((string)$status['step'],'Request failed.'),'Closed, held and unconfirmed requests are not labelled failed: '.$state);
}
echo "Unverified cancellation reason passed.\n";
$nativeSuccessful=true;
