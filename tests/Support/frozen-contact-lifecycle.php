<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native funded lifecycle checks after account edits.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
// Parent supplies the native owner, registration product and contactProperty helper.
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{ContactRecovery,ContactSetup};
foreach([false,true]as$invalid){
    contactProperty($user,'phone',$invalid?'private-value':'(206) 555-0100');$user->unsetRelation('properties');
    $name=reservedFixtureName('lifecycle-frozen-'.(int)$invalid.'.test');
    $cart=commerceCart($user);commerceAdd($cart,$product,$name);commerceCheckout($user,$cart);
    $row=Domain::where('domain',$name)->sole()->operations()->sole();$snapshot=$row->payload;$rawPayload=$row->getRawOriginal('payload');
    DB::table('invoices')->where('id',$row->invoice_id)->update(['status'=>'paid']);
    InvoiceTransaction::create(['invoice_id'=>$row->invoice_id,'amount'=>$row->amount,'status'=>'succeeded']);
    contactProperty($user,'phone',$invalid?'(206) 555-0100':'private-value');$user->unsetRelation('properties');
    $before=$row->fresh()->getRawOriginal();$money=serialize([DB::table('invoices')->get(),DB::table('invoice_items')->get(),DB::table('invoice_transactions')->get()]);
    ContactRecovery::hold($row->id);$after=$row->fresh();
    same($snapshot,$after->payload,'Real funded lifecycle uses the frozen snapshot after a native account edit');
    if($invalid){same($rawPayload,$after->getRawOriginal('payload'),'Contact-validation hold preserves encrypted payload bytes');}
    same($invalid?'held':'awaiting_payment',$after->state,'Lifecycle readiness follows frozen contacts');
    same($invalid?'contact_details_required':null,$after->safe_code,'Only invalid frozen contacts need readmission');
    if($invalid){
        contactReject(fn()=>ContactSetup::requireReady($after));
        $fixed=ContactRecovery::replace($user,$row->id,'Owner corrected native account phone');
        ContactSetup::requireReady($fixed);
        same(['queued',null,'contact_details_required','Owner corrected native account phone'],[$fixed->state,$fixed->safe_code,$fixed->policy['contact_readmissions'][0]['previous_code'],$fixed->policy['contact_readmissions'][0]['reason']],'Audited refresh records its hold and returns registration to the queue');
        same('+1.2065550100',$fixed->payload['contacts']['registrant']['phone'],'Audited readmission is the only route to fresh native contacts');
        same($user->id,$fixed->policy['contact_readmissions'][0]['actor_id'],'Contact refresh records owner authority');
        foreach(['registry','registry_schema']as$key){same($snapshot[$key]??null,$fixed->payload[$key]??null,'Contact readmission preserves other desired inputs');}
    }else{
        ContactSetup::requireReady($after);same($before,$after->getRawOriginal(),'Valid frozen setup never gains a hold from an invalid account edit');
    }
    same($money,serialize([DB::table('invoices')->get(),DB::table('invoice_items')->get(),DB::table('invoice_transactions')->get()]),'Lifecycle check and readmission preserve native financial rows');
}
contactProperty($user,'phone','(206) 555-0100');$user->unsetRelation('properties');
