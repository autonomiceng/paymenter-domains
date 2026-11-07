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
Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($op->domain->fresh(),'operation_review',time(),$op->id);
$row=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($op->domain->fresh())['Registrar'];
expect(str_contains((string)$row['sub'],'Request failed. Check request #'.$op->id.'.'),'Registrar row names the failed request outcome');
echo "Unverified cancellation reason passed.\n";
$nativeSuccessful=true;
