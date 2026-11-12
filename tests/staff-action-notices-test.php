<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use App\Models\Notification;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\StaffAlertSchema;
StaffAlertSchema::install();
$viewer=staffUser([Staff::VIEW]);
$other=staffUser(['admin.invoices.update']);
$op=$held('staff-notice-placeholder.com');
$url=OperationResource::getUrl('view',['record'=>$op->id]);
$inbox=fn()=>Notification::where('url',$url)->orderBy('id')->get();
same(0,$inbox()->count(),'No action has happened yet');
$key=$ticket($op,'release');
$run($op,'release',$key);
foreach([$staff,$viewer] as $recipient){
    same(1,$inbox()->where('user_id',$recipient->id)->count(),'Release creates one request-link notice per permitted recipient');
}
commerceReject(fn()=>$run($op->fresh(),'release',$key));
same(1,$inbox()->where('user_id',$viewer->id)->count(),'A consumed review ticket cannot send another notice');
$run($op->fresh(),'hold');
$run($op->fresh(),'release');
$run($op->fresh(),'hold');
$run($op->fresh(),'refund');
$notices=$inbox();
foreach([$staff,$viewer] as $recipient){
    $rows=$notices->where('user_id',$recipient->id)->values();
    same(['Hold released','Request on hold','Hold released','Request on hold','Request cancelled'],$rows->pluck('title')->all(),
        'Every completed action has its own bell item, including repeat holds');
    foreach($rows as $notice){
        expect(str_contains($notice->body,$op->domain->domain),'Notice identifies the domain');
        expect(str_contains($notice->body,trim($staff->first_name.' '.$staff->last_name)),'Notice identifies the actor');
        same($url,$notice->url,'Notice links to the exact request');
    }
}
same(0,$notices->where('user_id',$other->id)->count(),'Staff without domain view rights receive no request details');
same(0,$notices->where('user_id',$user->id)->count(),'The customer receives no staff action notice');
echo "Staff action bell notices: exact request, actor, repeated actions, consumed ticket and recipient permissions passed.\n";
$nativeSuccessful=true;
