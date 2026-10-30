<?php
// Mandatory transaction notices with optional schedules off, and retained audit rotation.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/transfer-fixture.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/reminder-mail.php';
use Illuminate\Support\Facades\{DB,Mail};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Services\{AuditArchives,ReminderSchedules,Reminders,StaffSettingsReview as Review,StaffSettingsWrites,Runtime,RuntimeSchema,RuntimeWork,RuntimePorts};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff};
$staff=staffUser([...array_keys(Staff::permissions())]);$reminders=new Reminders($clock,$bindings);
foreach($tlds as $t){ReminderSchedules::enroll($t->server_id);}
foreach($tlds as $i=>$t){
    $clock->instant=$now;$op=$newTransfer(placeholderComFixtureName('mandatory-'.$i.'-placeholder.com'),$i,true,false);
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
$op=$newTransfer(placeholderComFixtureName('manual-outcome-placeholder.com'),0,true,false);$id=DB::table('domains_reminder_deliveries')->insertGetId(['domain_id'=>$op->domain_id,'delivery_key'=>hash('sha256','manual-review'),'template'=>'domains_transfer_code_paid','state'=>'uncertain','created_at'=>$now->getTimestamp()]);
$writer=new StaffSettingsWrites($native,$sellers,$bindings);
$reviewFor=static function(string $action,int $serverId)use($staff):Review{$stamp=Review::stamp($serverId,null);return new Review($staff->id,$serverId,null,$action,Review::ticket($staff->id,$serverId,null,$action,$stamp),'Reject invalid notification review');};
$rejectUnchanged=static function(Review $review,array $data,string $table,int $id,string $message)use($writer,$now):void{
    $before=(array)DB::table($table)->find($id);
    try{$writer->run($review,$data,$now);expect(false,'Expected notification review refusal: '.$message);}
    catch(DomainException $error){same($message,$error->getMessage(),'Notification review refusal reaches its intended guard');}
    same($before,(array)DB::table($table)->find($id),'Rejected '.$review->action.' leaves its row unchanged');
};
$rejectUnchanged($reviewFor('delivery_review',$t->server_id),['delivery_id'=>$id],'domains_reminder_deliveries',$id,'Explicit notification confirmation required.');
$rejectUnchanged($reviewFor('delivery_review',$tlds[1]->server_id),['delivery_id'=>$id,'confirmed'=>true],'domains_reminder_deliveries',$id,'Current notification claim required.');
$savedFingerprint=$op->domain->account_fingerprint;DB::table('domains_domains')->where('id',$op->domain_id)->update(['account_fingerprint'=>'mismatched-review-fingerprint']);
try{$rejectUnchanged($reviewFor('delivery_review',$t->server_id),['delivery_id'=>$id,'confirmed'=>true],'domains_reminder_deliveries',$id,'Current notification claim required.');}
finally{DB::table('domains_domains')->where('id',$op->domain_id)->update(['account_fingerprint'=>$savedFingerprint]);}
$r=new Review($staff->id,$t->server_id,null,'delivery_review',Review::ticket($staff->id,$t->server_id,null,'delivery_review',Review::stamp($t->server_id,null)),'Independently checked notification outcome');
same([$id],array_keys(SettingsActions::openDeliveries($t->server_id)),'Mark notification checked offers the open one');$before=Mail::queued(DomainNotice::class)->count();$writer->run($r,['delivery_id'=>$id,'confirmed'=>true]);same([],SettingsActions::openDeliveries($t->server_id),'A checked notification is no longer offered');
same('reviewed',DB::table('domains_reminder_deliveries')->find($id)->state,'Original claim retained as reviewed');same($before,Mail::queued(DomainNotice::class)->count(),'Manual review does not resend');
$rejectUnchanged($reviewFor('delivery_review',$t->server_id),['delivery_id'=>$id,'confirmed'=>true],'domains_reminder_deliveries',$id,'Current notification claim required.');
$rejectUnchanged($r,['delivery_id'=>$id,'confirmed'=>true],'domains_reminder_deliveries',$id,'Review key used.');
$key=\Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($op->domain,'reminder_failed',$now->getTimestamp());$alert=DB::table('domains_obligation_alerts')->where('alert_key',$key)->sole();
$alertId=(int)$alert->id;
$otherKey=\Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($op->domain,'unknown_expiry',$now->getTimestamp());$otherAlert=DB::table('domains_obligation_alerts')->where('alert_key',$otherKey)->sole();
same([$alertId],array_keys(SettingsActions::openAlerts($t->server_id)),'Mark alert checked offers only the open notification alert');same([],SettingsActions::openAlerts($tlds[1]->server_id),'Another account offers none');
$rejectUnchanged($reviewFor('alert_review',$t->server_id),['alert_id'=>$alertId],'domains_obligation_alerts',$alertId,'Explicit notification check confirmation required.');
$rejectUnchanged($reviewFor('alert_review',$t->server_id),['alert_id'=>$otherAlert->id,'confirmed'=>true],'domains_obligation_alerts',(int)$otherAlert->id,'Current notification check alert required.');
$rejectUnchanged($reviewFor('alert_review',$tlds[1]->server_id),['alert_id'=>$alertId,'confirmed'=>true],'domains_obligation_alerts',$alertId,'Current notification check alert required.');
$savedFingerprint=$op->domain->account_fingerprint;DB::table('domains_domains')->where('id',$op->domain_id)->update(['account_fingerprint'=>'mismatched-review-fingerprint']);
try{$rejectUnchanged($reviewFor('alert_review',$t->server_id),['alert_id'=>$alertId,'confirmed'=>true],'domains_obligation_alerts',$alertId,'Current notification check alert required.');}
finally{DB::table('domains_domains')->where('id',$op->domain_id)->update(['account_fingerprint'=>$savedFingerprint]);}
$r=new Review($staff->id,$t->server_id,null,'alert_review',Review::ticket($staff->id,$t->server_id,null,'alert_review',Review::stamp($t->server_id,null)),'Independently inspected notification check');
$writer->run($r,['alert_id'=>$alertId,'confirmed'=>true],$now);same([],SettingsActions::openAlerts($t->server_id),'A closed alert is no longer offered');expect(DB::table('domains_obligation_alerts')->find($alertId)->resolved_at!==null,'Manual check disposition retained');
$rejectUnchanged($reviewFor('alert_review',$t->server_id),['alert_id'=>$alertId,'confirmed'=>true],'domains_obligation_alerts',$alertId,'Current notification check alert required.');
$rejectUnchanged($r,['alert_id'=>$alertId,'confirmed'=>true],'domains_obligation_alerts',$alertId,'Review key used.');
same(['Review notification outcome','Review notification check'],array_column(Review::historyRows(App\Models\Server::findOrFail($t->server_id)),'action'),'Both independent notification reviews retain audit rows');
// Each choice reads apart, even two notices for one domain in the same minute.
tldPanel();Paymenter\Extensions\Servers\Domains\Services\PslSchema::install();$server=$t->server_id;$at=$now->getTimestamp();$old=$newTransfer(placeholderComFixtureName('oldest-notice-placeholder.com'),0,true,false);
$notify=static fn(int $domain,string $key,string $template)=>DB::table('domains_reminder_deliveries')->insertGetId(['domain_id'=>$domain,'delivery_key'=>hash('sha256',$key),'template'=>$template,'state'=>'uncertain','created_at'=>$at]);
$alarm=static fn(int $domain,string $key,string $code)=>DB::table('domains_obligation_alerts')->insertGetId(['alert_key'=>hash('sha256',$key),'domain_id'=>$domain,'generation'=>Paymenter\Extensions\Servers\Domains\Models\Domain::findOrFail($domain)->authority_generation,'code'=>$code,'first_seen'=>$at,'last_seen'=>$at]);
$oldDelivery=$notify($old->domain_id,'oldest','domains_domain_expired');$oldAlert=$alarm($old->domain_id,'oldest','reminder_failed');
foreach(range(1,100) as $i){$notify($op->domain_id,'newer-'.$i,'domains_transfer_code_reminder');$alarm($op->domain_id,'newer-'.$i,'template_failed');}
$paid=$notify($op->domain_id,'same-minute','domains_transfer_code_paid');$offered=SettingsActions::openDeliveries($server);
same(100,count(array_unique($offered)),'Two notices in one minute read apart');
same($op->domain->domain.' · Transfer needs an auth code · '.gmdate('M j, Y H:i',$at).' UTC · #'.$paid,$offered[$paid],'A choice names its type, time and reference');
// A search reaches a notice past the newest 100, and the form takes it. Once closed, it can no longer be picked.
foreach([['delivery_review','delivery_id',$oldDelivery,'Domain expired'],['alert_review','alert_id',$oldAlert,'Renewal reminder check']] as [$action,$field,$oldest,$type]){
    $name=setupAction($action,$server);$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server])->mountAction($name)->assertActionMounted($name);
    expect(!array_key_exists($oldest,$action==='alert_review'?SettingsActions::openAlerts($server):$offered),'The oldest starts outside the first 100');
    $page->assertSchemaComponentExists($field,checkComponentUsing:function($select)use($oldest,$type){
        foreach(['oldest-notice','#'.$oldest] as $search){same([$oldest],array_keys($select->getSearchResults($search)),'Search reaches the old notice by '.$search);}
        same([$oldest],array_keys($select->getSearchResults($type)),'Search reaches it by its type words');same([],$select->getSearchResults('%'),'A wildcard is plain text');return true;});
    $page->setActionData([$field=>$oldest,'confirmed'=>true,'reason'=>'Checked the oldest notice'])->callMountedAction()->assertHasNoActionErrors();
    $page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server])->mountAction($name);
    $page->setActionData([$field=>$oldest,'confirmed'=>true,'reason'=>'Checked it a second time'])->callMountedAction()->assertHasActionErrors([$field]);
}
same('reviewed',DB::table('domains_reminder_deliveries')->find($oldDelivery)->state,'The old notification closes');expect(DB::table('domains_obligation_alerts')->find($oldAlert)->resolved_at!==null,'The old alert closes');
same([],SettingsActions::openDeliveries($tlds[1]->server_id,id:$paid),'Another account cannot resolve the choice');
echo "Paired mandatory notices, optional reminder suppression,100-edit audit rotation and no-resend disposition passed.\n";$nativeSuccessful=true;
