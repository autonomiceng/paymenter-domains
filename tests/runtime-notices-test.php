<?php
// Mandatory transaction notices with optional schedules off, and retained audit rotation.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-fixture.php';require __DIR__.'/Support/staff-panel.php';require __DIR__.'/Support/reminder-mail.php';
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Services\{AuditArchives,ReminderSchedules,Reminders,StaffSettingsReview as Review,StaffSettingsWrites,Runtime,RuntimeSchema,RuntimeWork,RuntimePorts};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
$staff=staffUser([...array_keys(Staff::permissions())]);$reminders=new Reminders($clock,$bindings);
foreach($tlds as $t){ReminderSchedules::enroll($t->server_id);}
foreach($tlds as $i=>$t){
    $clock->instant=$now;$op=$newTransfer('mandatory-'.$i.'.com',$i,true,false);
    expect($reminders->remind($op->domain_id),'Paid notice sends with customer schedules off');
    same(false,$reminders->remind($op->domain_id),'Mandatory notices deduplicate');
    $clock->instant=$now->modify('+23 days');same(false,$reminders->remind($op->domain_id),'Optional final-week reminder remains off');
    $clock->instant=$now->modify('+30 days');$inputs->expireMissingCode($op->id);expect($reminders->remind($op->domain_id),'Recorded credit notice sends with schedules off');
    same('domains_domain_order_refunded',Mail::queued(DomainNotice::class)->last()->emailTemplate->key,'Actual credit notice only');
}
// Rotation retains every prior audit row and permits the101st edit.
$clock->instant=$now;$t=$tlds[0];$q=DB::table('domains_reminder_schedules')->where('server_id',$t->server_id);$audit=[];
for($i=1;$i<=100;$i++){$audit[]=['revision'=>$i,'actor_id'=>$staff->id,'reason'=>'prior_review'];}
$q->update(['revision'=>101,'audit'=>encrypt($audit)]);ReminderSchedules::save($staff,$t->server_id,101,false,'1,7','3','review_rotation');
same(1,count(decrypt($q->sole()->audit)),'Active audit starts next retained segment');
$archive=DB::table(AuditArchives::TABLE)->where('server_id',$t->server_id)->where('family','reminders')->sole();expect(!str_contains($archive->payload,'prior_review'),'Schedule history encrypted');same(100,count(json_decode(AuditArchives::latest($t->server_id,'reminders'),true)['rows']),'All100 prior rows retained');
expect(!App\Models\Server::find($t->server_id)->settings()->where('key','like','domains_reminder_audit.%')->exists(),'Archives stay outside native settings loads');
// A manual disposition records staff review without any resend or release of the claim.
$op=$newTransfer('manual-outcome.com',0,true,false);$id=DB::table('domains_reminder_deliveries')->insertGetId(['domain_id'=>$op->domain_id,'delivery_key'=>hash('sha256','manual-review'),'template'=>'domains_transfer_code_paid','state'=>'uncertain','created_at'=>$now->getTimestamp()]);
$r=new Review($staff->id,$t->server_id,null,'delivery_review',Review::ticket($staff->id,$t->server_id,null,'delivery_review',Review::stamp($t->server_id,null)),'Independently checked notification outcome');
$before=Mail::queued(DomainNotice::class)->count();(new StaffSettingsWrites($native,$sellers,$bindings))->run($r,['delivery_id'=>$id,'confirmed'=>true]);
same('reviewed',DB::table('domains_reminder_deliveries')->find($id)->state,'Original claim retained as reviewed');same($before,Mail::queued(DomainNotice::class)->count(),'Manual review does not resend');
$key=\Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($op->domain,'reminder_failed',$now->getTimestamp());$alert=DB::table('domains_obligation_alerts')->where('alert_key',$key)->sole();
$r=new Review($staff->id,$t->server_id,null,'alert_review',Review::ticket($staff->id,$t->server_id,null,'alert_review',Review::stamp($t->server_id,null)),'Independently inspected notification check');
(new StaffSettingsWrites($native,$sellers,$bindings))->run($r,['alert_id'=>$alert->id,'confirmed'=>true],$now);expect(DB::table('domains_obligation_alerts')->find($alert->id)->resolved_at!==null,'Manual check disposition retained');
same(['Review notification outcome','Review notification check'],array_column(Review::historyRows(App\Models\Server::findOrFail($t->server_id)),'action'),'Both independent notification reviews retain audit rows');
echo "Paired mandatory notices, optional reminder suppression,100-edit audit rotation and no-resend disposition passed.\n";$nativeSuccessful=true;
