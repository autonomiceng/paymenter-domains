<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The reader cutover keeps every recent staff and reminder change visible after a batch rollover.
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';
use App\Models\Server;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AuditArchives,AuditBatchRetirement,ReminderSchedules,StaffSettingsReview};
extract(commerceFixture());$server=Server::findOrFail($tlds[0]->server_id);
$newServer=Server::create(['name'=>'Synthetic upgrade enrollment','type'=>'server','extension'=>'Domains','enabled'=>true]);
DB::table(AuditArchives::TABLE)->where('server_id',$server->id)->delete();
$server->settings()->whereIn('key',[StaffSettingsReview::KEY,StaffSettingsReview::KEY.'.catalogue'])->delete();
$change=static fn(string $key,string $action,string $at)=>['key'=>$key,'at'=>$at,'phase'=>'completed','action'=>$action,'actor_id'=>null,'reason'=>'Synthetic '.$key];
// A full archived batch of pricing and card fee changes, then one current change after the rollover.
$archived=array_map(static fn(int $i)=>$change('archived-'.$i,$i%2?'Edit card fee':'Edit default pricing',sprintf('2026-11-01T%02d:%02d:00+00:00',intdiv($i,60),$i%60)),range(0,StaffSettingsReview::HISTORY_LIMIT-1));
DB::transaction(static fn()=>AuditArchives::retainBatch($server->id,'staff_settings',json_encode($archived)));
$server->settings()->create(['key'=>StaffSettingsReview::KEY,'encrypted'=>true,'type'=>'string','value'=>json_encode([$change('current','Edit card fee','2026-11-02T01:00:00+00:00')])]);
ReminderSchedules::enroll($server->id);
Schema::table('domains_reminder_schedules',static fn($t)=>$t->text('audit')->nullable());DB::table('domains_reminder_schedules')->update(['audit'=>encrypt([])]);
$reminderAt='2026-11-02T02:00:00+00:00';
DB::table('domains_reminder_schedules')->where('server_id',$server->id)->update(['audit'=>encrypt([['revision'=>2,'at'=>$reminderAt,'reason'=>'Synthetic reminder change','before'=>['enabled'=>false],'after'=>['enabled'=>true]]])]);
// Enrollment can run with the required legacy column before the retirement migration.
Schema::table('domains_reminder_schedules',static fn($t)=>$t->text('audit')->nullable(false)->change());
$account=Account::withVerifiedBindingWrite(static fn()=>Account::create(['server_id'=>$newServer->id,'registrar_key'=>'enom','environment'=>'test','stable_identity'=>'upgrade-enrollment']));
ReminderSchedules::enroll($newServer->id);
$enrolled=DB::table('domains_reminder_schedules')->where('server_id',$newServer->id)->sole();
same([$account->account_fingerprint,true,[1,7,30,60],[3],[]],[$enrolled->account_fingerprint,(bool)$enrolled->enabled,json_decode($enrolled->before_days,true),json_decode($enrolled->after_days,true),decrypt($enrolled->audit)],'Enrollment preserves account identity and initializes a readable legacy audit');
ReminderSchedules::enroll($newServer->id);
same((array)$enrolled,(array)DB::table('domains_reminder_schedules')->where('server_id',$newServer->id)->sole(),'Repeated enrollment preserves the legacy schedule');
$keys=static fn()=>array_values(array_filter(array_column(StaffSettingsReview::historyRows($server->fresh()),'key'),static fn($k)=>is_string($k)&&preg_match('/^(archived-\d+|current)$/',$k)===1));
$reminders=static fn()=>count(array_filter(TldFacts::accountHistory($server->id)??[],static fn(array $r)=>$r['time']===$reminderAt));
// Old batches hold the history until the cutover imports them and switches the readers.
AuditBatchRetirement::install();
$expected=[...array_map(static fn(int $i)=>'archived-'.$i,range(1,StaffSettingsReview::HISTORY_LIMIT-1)),'current'];
same($expected,$keys(),'Earlier recent pricing and card fee changes stay visible after the cutover');
same(1,$reminders(),'Reminder history stays visible after the cutover');
echo "Recent staff and reminder history stays visible across the audit cutover.\n";$nativeSuccessful=true;
