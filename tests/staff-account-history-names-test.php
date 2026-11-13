<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Account history names the switch that changed, lists reminder email changes, and folds system runs into one row.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Services\{ReminderSchedules,RenewalBillingSettings,StaffSettingsReview as Review,StaffSettingsWrites};
extract(commerceFixture(true));tldPanel();Carbon\Carbon::setTestNow($now);$server=(int)$tlds[0]->server_id;
$u=staffUser([Staff::VIEW,Staff::POLICY,Review::permission('renewal_billing'),'admin.users.view']);Auth::setUser($u);
$writes=new StaffSettingsWrites($native,$sellers,$bindings);
$billing=RenewalBillingSettings::read($server);
$review=static fn()=>new Review($u->id,$server,null,'renewal_billing',Review::ticket($u->id,$server,null,'renewal_billing',Review::stamp($server,null)),'Turned on');
$writes->run($review(),['scheduled'=>$billing['scheduled'],'manual'=>!$billing['manual'],'issue_days'=>$billing['issue_days'],'due_days'=>$billing['due_days'],'confirmed'=>true]);
same('Customer can renew early turned '.(!$billing['manual']?'on':'off'),TldFacts::accountHistory($server)[0]['change'],'The Customer can renew early switch is named, not Edit renewal schedule');
ReminderSchedules::enroll($server);$row=Illuminate\Support\Facades\DB::table('domains_reminder_schedules')->where('server_id',$server)->sole();
Carbon\Carbon::setTestNow($now->modify('+1 minute'));
ReminderSchedules::save($u,$server,(int)$row->revision,false,'1,7,30,60','3','Turned off');
// The reminder audit stamps the wall clock, not the frozen test clock, so find the row rather than read the first.
$first=array_values(array_filter(TldFacts::accountHistory($server),fn(array $r)=>str_starts_with($r['change'],'Reminder emails')))[0]??['change'=>null,'by'=>null];
same('Reminder emails turned off',$first['change'],'A Reminder emails change is in account history');
same(trim($u->first_name.' '.$u->last_name)?:$u->email,$first['by'],'It names who changed it');
// A days-only change keeps the switch and lists the new days.
Carbon\Carbon::setTestNow($now->modify('+2 minutes'));$row=Illuminate\Support\Facades\DB::table('domains_reminder_schedules')->where('server_id',$server)->sole();
ReminderSchedules::save($u,$server,(int)$row->revision,false,'1,7,30','3,10','Shorter schedule');
$schedule=array_values(array_filter(TldFacts::accountHistory($server),fn(array $r)=>$r['change']==='Reminder schedule changed'))[0]??['reason'=>null];
same('Days before expiry: 1, 7, 30; days after: 3, 10. Shorter schedule',$schedule['reason'],'A schedule change lists the saved days and the reason');
// Back-to-back system runs read as one row.
$rows=[['time'=>'2026-11-10T04:10:03+00:00','change'=>'Prices published','reason'=>'.org: Prices recalculated using the current pricing and sales settings.','by'=>'System','tld'=>'org'],
    ['time'=>'2026-11-10T04:10:02+00:00','change'=>'Prices published','reason'=>'.net: Prices recalculated using the current pricing and sales settings.','by'=>'System','tld'=>'net'],
    ['time'=>'2026-11-10T04:10:01+00:00','change'=>'Prices published','reason'=>'.com: Prices recalculated using the current pricing and sales settings.','by'=>'System','tld'=>'com'],
    ['time'=>'2026-11-10T04:09:00+00:00','change'=>'Price freshness changed','reason'=>'Staff change','by'=>'Staff #1']];
same([['time'=>'2026-11-10T04:10:03+00:00','change'=>'Prices published for 3 TLDs','reason'=>'.com, .net, .org: Prices recalculated using the current pricing and sales settings.','by'=>'System'],$rows[3]],TldFacts::group($rows),'Three system rows fold into one with a count, and the staff row stays near the top');
// Runs a day apart stay separate rows, even with no other row between them.
$daily=[['time'=>'2026-11-10T04:10:00+00:00','change'=>'Prices published','reason'=>'.com: Prices recalculated using the current pricing and sales settings.','by'=>'System','tld'=>'com'],
    ['time'=>'2026-11-09T04:10:00+00:00','change'=>'Prices published','reason'=>'.com: Prices recalculated using the current pricing and sales settings.','by'=>'System','tld'=>'com']];
same([[...array_diff_key($daily[0],['tld'=>1])],[...array_diff_key($daily[1],['tld'=>1])]],TldFacts::group($daily),'Daily system runs stay separate rows');
// Runs stop at staff rows, other system kinds, or rows without a TLD.
$staff=[...$rows[3],'time'=>'2026-11-10T04:10:02+00:00'];
foreach([[$rows[0],$staff,$rows[2]],[$rows[0],[...$rows[1],'change'=>'Prices synced'],$rows[2]],[$rows[0],array_diff_key($rows[1],['tld'=>1]),$rows[2]]] as $separate){
    $expected=array_map(fn($r)=>array_diff_key($r,['tld'=>1]),$separate);
    same($expected,TldFacts::group($separate),'An intervening row ends the system run');
}
// Mixed audit sources sort by instant; a raw invalid time stays readable at the end.
$account=App\Models\Server::findOrFail($server);
foreach(['2026-11-10 01:30:00-05:00'=>'Synthetic newest','2026-11-10T06:00:00Z'=>'Synthetic oldest','zz-invalid-time'=>'Synthetic invalid'] as $at=>$action){
    Illuminate\Support\Facades\DB::transaction(fn()=>Review::append($account,['key'=>'synthetic-'.$action,'at'=>$at,'action'=>$action,'phase'=>'completed','actor_id'=>$u->id,'reason'=>'Readable retained detail','input'=>[],'before'=>[],'after'=>[]]));
}
$reminder=Illuminate\Support\Facades\DB::table('domains_audit_archives')->where('server_id',$server)->where('family','reminders');
$saved=(clone $reminder)->orderBy('id')->first();$audit=json_decode(Illuminate\Support\Facades\Crypt::decryptString($saved->payload),true);$audit['at']='2026-11-10 06:15:00 UTC';
$reminder->delete();
$append=static fn(array $entry)=>Illuminate\Support\Facades\DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\AuditEvents::append($server,'reminders',$entry));
$append($audit);
$history=TldFacts::accountHistory($server);
$selected=array_values(array_filter($history,fn($r)=>str_starts_with($r['change'],'Synthetic ')||str_starts_with($r['change'],'Reminder emails')));
foreach([['reason'=>'Missing time'],['at'=>'not-a-time','reason'=>'Invalid time'],['at'=>'2026-11-10T06:20:00Z','before'=>['enabled'=>false],'after'=>['enabled'=>false,'before_days'=>'invalid JSON','after_days'=>'[366]'],'reason'=>'Retained invalid days']] as $entry){$append($entry);}
$before=(clone $reminder)->orderBy('id')->get()->toJson();$mixedHistory=TldFacts::accountHistory($server);
expect(is_array($mixedHistory),'Readable mixed events keep account history readable');
$reminderRows=array_values(array_filter($mixedHistory,fn($r)=>str_starts_with($r['change'],'Reminder ')));
same(['Reminder schedule changed','Reminder emails turned off'],array_column($reminderRows,'change'),'Only readable reminder entries survive');
same('Days before expiry: none; days after: none. Retained invalid days',$reminderRows[0]['reason'],'Malformed days use the existing none fallback');
same($before,(clone $reminder)->orderBy('id')->get()->toJson(),'History reads preserve event bytes');
$panel=\Filament\Facades\Filament::getCurrentPanel();
Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarHistory::registerRoutes($panel));
app('router')->getRoutes()->refreshNameLookups();
$last=(clone $reminder)->orderByDesc('id')->first();
foreach(['broken ciphertext',Illuminate\Support\Facades\Crypt::encryptString('null')] as $unreadable){
    (clone $reminder)->where('id',$last->id)->update(['payload'=>$unreadable]);
    same(null,TldFacts::accountHistory($server),'Unreadable reminder event makes combined history unreadable');
    \Livewire\Livewire::actingAs($u)->test(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarHistory::class,['account'=>$server])
        ->assertSee(\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::UNREADABLE)->assertDontSee('No changes yet');
}
(clone $reminder)->where('id',$last->id)->update(['payload'=>$last->payload]);
Illuminate\Support\Facades\DB::table('domains_reminder_schedules')->where('server_id',$server)->delete();
$afterRemoval=TldFacts::accountHistory($server);
same($reminderRows,array_values(array_filter($afterRemoval,fn($r)=>str_starts_with($r['change'],'Reminder '))),'Reminder events and their details survive schedule removal');
same(['Synthetic newest','Reminder emails turned off','Synthetic oldest','Synthetic invalid'],array_column($selected,'change'),'Parsed event times sort newest first with invalid times last');
same(['zz-invalid-time','Readable retained detail'],[$selected[3]['time'],$selected[3]['reason']],'Invalid audit time retains its details');
$nativeSuccessful=true;echo "Staff account history names passed\n";
