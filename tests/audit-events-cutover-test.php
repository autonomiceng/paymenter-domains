<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';
use App\Models\Server;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{AuditArchives,AuditEvents,AuditBatchRetirement,StaffSettingsReview as Review};
extract(commerceFixture());$server=Server::findOrFail($tlds[0]->server_id);
$entry=['key'=>'cutover','at'=>'2026-10-21T10:00:00Z','action'=>'Edit default rule','phase'=>'completed','reason'=>'Keep original evidence'];
DB::transaction(fn()=>Review::append($server,$entry));
same(0,$server->settings()->whereIn('key',array_keys(AuditEvents::SETTINGS))->count(),'New events never create native audit settings');
same($entry,array_slice(AuditEvents::recent($server->id,'staff_settings'),-1)[0],'New writer stores one encrypted event');
// Rehearse the final drain of old batches after the preparatory deployment.
$server->settings()->create(['key'=>'domains_staff_settings_audit','encrypted'=>true,'type'=>'string','value'=>json_encode([[...$entry,'key'=>'last-batch']])]);
$server->settings()->create(['key'=>'domains_staff_settings_audit.keep','type'=>'string','value'=>'unrelated']);
AuditBatchRetirement::install();
same(0,DB::table(AuditArchives::TABLE)->whereNull('event_key')->count(),'Verified legacy batches retired');
expect(!Schema::hasColumn('domains_reminder_schedules','audit'),'Retired reminder batch column removed');
same('unrelated',$server->settings()->where('key','domains_staff_settings_audit.keep')->sole()->value,'Only the exact retired keys removed');
$before=DB::table(AuditArchives::TABLE)->orderBy('id')->get()->toJson();AuditBatchRetirement::install();
same($before,DB::table(AuditArchives::TABLE)->orderBy('id')->get()->toJson(),'Cutover rerun preserves all event bytes');
try{DB::transaction(function()use($server,$entry){Review::append($server,[...$entry,'key'=>'rollback']);throw new DomainException('rollback');});}catch(DomainException){}
same($before,DB::table(AuditArchives::TABLE)->orderBy('id')->get()->toJson(),'Audit and local changes roll back together');
echo "Audit writers, final checked import, exact key retirement and reruns passed.\n";$nativeSuccessful=true;
