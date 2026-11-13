<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';
use App\Models\Server;
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AuditArchives,AuditEvents,AuditEventSchema,ReminderSchedules};
extract(commerceFixture());$server=Server::findOrFail($tlds[0]->server_id);
DB::table(AuditArchives::TABLE)->where('server_id',$server->id)->delete();
$server->settings()->whereIn('key',['domains_staff_settings_audit','domains_staff_settings_audit.catalogue'])->delete();
$entry=['key'=>'same-review','at'=>'2026-10-21T03:00:00-07:00','phase'=>'completed','reason'=>'retained history'];
DB::transaction(fn()=>AuditArchives::retainBatch($server->id,'staff_settings',json_encode([$entry,$entry])));
$global=[...$entry,'key'=>'global-choice-import'];
DB::transaction(fn()=>AuditArchives::retainBatch(0,'staff_settings',json_encode([$global])));
$setting=$server->settings()->create(['key'=>'domains_staff_settings_audit','encrypted'=>true,'type'=>'string','value'=>json_encode([$entry])]);
ReminderSchedules::enroll($server->id);
legacyReminderAuditColumn();
DB::table('domains_reminder_schedules')->where('server_id',$server->id)->update(['audit'=>encrypt([['revision'=>2,'at'=>$entry['at'],'reason'=>'reminder history']])]);
DB::table('migrations')->where('migration',AuditEventSchema::MIGRATION)->delete();
Illuminate\Support\Facades\Schema::table(AuditArchives::TABLE,fn($t)=>$t->dropUnique(['event_key']));
AuditEventSchema::install(); // Exercise retained import without reopening completed batch retirement.
same([],AuditEvents::recent($server->id,'staff_settings'),'The schema migration leaves reader batches for the cutover');
same(json_encode([$entry]),$setting->fresh()->value,'The schema migration does not drain the current batch');
AuditEvents::import();
same([$entry,$entry,$entry],AuditEvents::recent($server->id,'staff_settings'),'Every original ordinal, including identical events, is retained');
same([$global],array_values(array_filter(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::historyRows($server),fn($row)=>$row['key']==='global-choice-import')),'Import preserves global history exactly once');
same('reminder history',AuditEvents::recent($server->id,'reminders')[0]['reason'],'Reminder history imported from its encrypted schedule');
same('[]',$setting->fresh()->value,'Mutable batch drained after archive creation');
$rows=DB::table(AuditArchives::TABLE)->orderBy('id')->get()->toJson();AuditEventSchema::install();AuditEvents::import();
same($rows,DB::table(AuditArchives::TABLE)->orderBy('id')->get()->toJson(),'Rerun preserves event identities and encrypted bytes');
// Preparatory deployment can still receive old batch writes. A later drain must import them once.
$setting->refresh();$setting->value=json_encode([[...$entry,'reason'=>'later write']]);$setting->save();
AuditEvents::import();same(4,count(AuditEvents::recent($server->id,'staff_settings')),'Later old-writer batch imported once');
expect(!str_contains(DB::table(AuditArchives::TABLE)->whereNotNull('event_key')->get()->toJson(),'retained history'),'Events stay encrypted');
try{DB::transaction(fn()=>AuditEvents::append($server->id,'staff_settings',['reason'=>str_repeat('x',64000)]));expect(false,'Oversized event accepted');}catch(DomainException $e){same('Audit entry too large. Changes held.',$e->getMessage(),'Payload cap enforced');}
// Corruption refuses the whole account drain; the source remains available for a person.
$setting->refresh();$setting->value='broken';$setting->save();$before=DB::table(AuditArchives::TABLE)->count();
try{AuditEvents::import();expect(false,'Corrupt source accepted');}catch(DomainException){}
same('broken',$setting->fresh()->value,'Unreadable source retained');same($before,DB::table(AuditArchives::TABLE)->count(),'Refused import has no partial events');
echo "Encrypted row import, duplicate ordinals, original times, reruns and corrupt-source retention passed.\n";$nativeSuccessful=true;
