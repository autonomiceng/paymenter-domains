<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// An upgrade before the reader cutover keeps every recent staff and reminder change visible after a batch rollover.
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';
use App\Models\Server;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts;
use Paymenter\Extensions\Servers\Domains\Services\{AuditArchives,AuditEventSchema,Installation,ReminderSchedules,StaffSettingsReview};
extract(commerceFixture());$server=Server::findOrFail($tlds[0]->server_id);
DB::table(AuditArchives::TABLE)->where('server_id',$server->id)->delete();
$server->settings()->whereIn('key',[StaffSettingsReview::KEY,StaffSettingsReview::KEY.'.catalogue'])->delete();
$change=static fn(string $key,string $action,string $at)=>['key'=>$key,'at'=>$at,'phase'=>'completed','action'=>$action,'actor_id'=>null,'reason'=>'Synthetic '.$key];
// A full archived batch of pricing and card fee changes, then one current change after the rollover.
$archived=array_map(static fn(int $i)=>$change('archived-'.$i,$i%2?'Edit card fee':'Edit default pricing',sprintf('2026-11-01T%02d:%02d:00+00:00',intdiv($i,60),$i%60)),range(0,StaffSettingsReview::HISTORY_LIMIT-1));
DB::transaction(static fn()=>AuditArchives::append($server->id,'staff_settings',json_encode($archived)));
$server->settings()->create(['key'=>StaffSettingsReview::KEY,'encrypted'=>true,'type'=>'string','value'=>json_encode([$change('current','Edit card fee','2026-11-02T01:00:00+00:00')])]);
ReminderSchedules::enroll($server->id);
$reminderAt='2026-11-02T02:00:00+00:00';
DB::table('domains_reminder_schedules')->where('server_id',$server->id)->update(['audit'=>encrypt([['revision'=>2,'at'=>$reminderAt,'reason'=>'Synthetic reminder change','before'=>['enabled'=>false],'after'=>['enabled'=>true]]])]);
$keys=static fn()=>array_values(array_filter(array_column(StaffSettingsReview::historyRows($server->fresh()),'key'),static fn($k)=>is_string($k)&&preg_match('/^(archived-\d+|current)$/',$k)===1));
$reminders=static fn()=>count(array_filter(TldFacts::accountHistory($server->id)??[],static fn(array $r)=>$r['time']===$reminderAt));
$before=$keys();
same(StaffSettingsReview::HISTORY_LIMIT,count($before),'The recent window is full before the upgrade');
expect(in_array('current',$before,true)&&in_array('archived-1',$before,true),'Archived and current changes are visible before the upgrade');
same(1,$reminders(),'Reminder history is visible before the upgrade');
DB::table('migrations')->where('migration',AuditEventSchema::MIGRATION)->delete();
Schema::table(AuditArchives::TABLE,static fn($t)=>$t->dropUnique(['event_key']));
(new Installation)->install();
same($before,$keys(),'Earlier recent pricing and card fee changes stay visible after the upgrade');
same(1,$reminders(),'Reminder history stays visible after the upgrade');
echo "Recent staff and reminder history stays visible across the audit event upgrade.\n";$nativeSuccessful=true;
