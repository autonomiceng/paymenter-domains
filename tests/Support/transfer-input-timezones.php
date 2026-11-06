<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
// Native datetime objects retain their offsets; raw audits use the configured database timezone.
$timezone=date_default_timezone_get();
foreach($transferEvidenceTimezones as $i=>$zone){
    $local=$new(placeholderComFixtureName('transfer-zone-'.$i.'-placeholder.com'));$audit=$new(placeholderComFixtureName('transfer-audit-zone-'.$i.'-placeholder.com'));$missingTransition($local);$missingTransition($audit);
    date_default_timezone_set($zone);$stamp=$now->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i:s');
    // Represent these native timestamps as stored by this timezone, without reinterpreting the UTC catalogue fixture.
    DB::table('domains_operations')->whereIn('id',[$local->id,$audit->id])->update(['created_at'=>$stamp]);
    DB::table('invoice_transactions')->whereIn('invoice_id',[$local->invoice_id,$audit->invoice_id])->update(['created_at'=>$stamp,'updated_at'=>$stamp]);
    $local->refresh();expect($local->created_at->getOffset()!==0,'Non-UTC native timestamp fixture');
    $inputs->paymentAccepted($local->id);same($paid,$local->fresh()->policy['paid_at'],'Transaction paid time normalized to original UTC instant');
    DB::table('invoice_transactions')->where('invoice_id',$audit->invoice_id)->update(['updated_at'=>'2026-10-17 00:00:00']);
    DB::table('audits')->insert(['event'=>'updated','auditable_type'=>App\Models\Invoice::class,'auditable_id'=>$audit->invoice_id,
        'old_values'=>'{"status":"pending"}','new_values'=>'{"status":"paid"}','created_at'=>$stamp,'updated_at'=>$stamp]);
    $inputs->paymentAccepted($audit->id);same($paid,$audit->fresh()->policy['paid_at'],'Local audit time normalized to original UTC instant');
    date_default_timezone_set($timezone);
}
