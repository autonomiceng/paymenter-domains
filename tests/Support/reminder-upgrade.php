<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\ReminderSchedules;
// Uses the existing native reminder fixture: encryption, native settings and migration history need Paymenter.
foreach($tlds as $accountTld){ReminderSchedules::enroll((int)$accountTld->server_id);}
$upgradeQuery=DB::table('domains_reminder_schedules');
$originalSchedules=$upgradeQuery->orderBy('server_id')->get()->map(fn($r)=>(array)$r)->all();
DB::table('domains_reminder_schedules')->where('server_id',$tlds[0]->server_id)->update(['enabled'=>false,'before_days'=>'[2,14]','after_days'=>'[5]']);
DB::table('domains_reminder_schedules')->where('server_id',$tlds[1]->server_id)->update(['enabled'=>true,'before_days'=>'[10]','after_days'=>'[4]']);
$customSchedules=$upgradeQuery->orderBy('server_id')->get()->map(fn($r)=>(array)$r)->all();
$oldMissing=App\Models\Server::create(['name'=>'Existing account without a schedule','type'=>'server','extension'=>'Domains','enabled'=>true]);
$legacyServerId=(int)$oldMissing->id;
Paymenter\Extensions\Servers\Domains\Models\Account::withVerifiedBindingWrite(static fn()=>Paymenter\Extensions\Servers\Domains\Models\Account::create(['server_id'=>$legacyServerId,'registrar_key'=>'enom','environment'=>'test','stable_identity'=>'existing-no-reminder']));
// The shared fixture is now a full install. Recreate this one pre-migration history boundary
// so the native migrator actually upgrades the existing account and preserves saved choices.
$upgradePaths=glob(dirname(__DIR__,2).'/Domains/database/migrations/2026_11_01_000047_preserve_reminder_defaults.php');
if($upgradePaths!==[]){DB::table('migrations')->where('migration','2026_11_01_000047_preserve_reminder_defaults')->delete();}
require_once __DIR__.'/legacy-reminder-audit.php';
$legacyAudit=legacyReminderAuditColumn();
try{app(Illuminate\Database\Migrations\Migrator::class)->run($upgradePaths,['force'=>true]);}finally{
    if($legacyAudit){Illuminate\Support\Facades\Schema::table('domains_reminder_schedules',static fn($t)=>$t->dropColumn('audit'));}
}
ReminderSchedules::enroll($legacyServerId);
same(false,(bool)DB::table('domains_reminder_schedules')->where('server_id',$legacyServerId)->value('enabled'),'Existing account without a schedule remains Off after upgrade and worker enrollment');
foreach($customSchedules as $saved){same($saved,(array)DB::table('domains_reminder_schedules')->where('server_id',$saved['server_id'])->sole(),'Upgrade preserves saved On, Off and custom days');}
$newServer=App\Models\Server::create(['name'=>'New reminder account','type'=>'server','extension'=>'Domains','enabled'=>true]);
$bindings->configure($newServer->id,'enom',Paymenter\Extensions\Servers\Domains\Data\Environment::Test,['token'=>'new-reminder-default']);
same(true,(bool)DB::table('domains_reminder_schedules')->where('server_id',$newServer->id)->value('enabled'),'Newly connected account gets reminder emails On');
foreach($originalSchedules as $saved){DB::table('domains_reminder_schedules')->where('server_id',$saved['server_id'])->update($saved);}
$offFacts=Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderFacts::emails($legacyServerId);
expect(str_contains($offFacts['cause'],'paused')&&str_contains($offFacts['cause'],'Turn on'),'Preserved Off schedule explains its pause and next step');
expect($offFacts['buttons'][0]->isVisible(),'Preserved Off schedule offers its existing turn-on switch');
$oldMail=config('settings.mail_disable');config(['settings.mail_disable'=>true]);
$mailFacts=Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderFacts::emails((int)$newServer->id);
same('warning',$mailFacts['tone'],'Enabled reminders warn when site mail is off');
expect(str_contains($mailFacts['cause'],'Settings')&&str_contains($mailFacts['cause'],'Mail'),'Missing mail setup gives its repair path');
config(['settings.mail_disable'=>$oldMail]);
