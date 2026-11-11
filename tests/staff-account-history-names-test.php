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
$reminder=Illuminate\Support\Facades\DB::table('domains_reminder_schedules')->where('server_id',$server);
$saved=(clone $reminder)->sole();$audit=decrypt($saved->audit);$audit[0]['at']='2026-11-10 06:15:00 UTC';
$reminder->update(['audit'=>encrypt([$audit[0]])]);
$history=TldFacts::accountHistory($server);
$selected=array_values(array_filter($history,fn($r)=>str_starts_with($r['change'],'Synthetic ')||str_starts_with($r['change'],'Reminder emails')));
// Invalid individual entries do not hide readable reminder or settings history.
$malformed=[false,['reason'=>'Missing time'],['at'=>'not-a-time','reason'=>'Invalid time']];
$invalidDays=['at'=>'2026-11-10T06:20:00Z','before'=>['enabled'=>false],'after'=>['enabled'=>false,'before_days'=>'invalid JSON','after_days'=>'[366]'],'reason'=>'Retained invalid days'];
$mixed=encrypt([$audit[0],...$malformed,$invalidDays]);$reminder->update(['audit'=>$mixed]);
$mixedHistory=TldFacts::accountHistory($server);
expect(is_array($mixedHistory),'A readable mixed audit keeps account history readable');
$reminderRows=array_values(array_filter($mixedHistory,fn($r)=>str_starts_with($r['change'],'Reminder ')));
same(['Reminder schedule changed','Reminder emails turned off'],array_column($reminderRows,'change'),'Only readable reminder entries survive');
same('Days before expiry: none; days after: none. Retained invalid days',$reminderRows[0]['reason'],'Malformed stored days use the existing none fallback');
expect(in_array('Synthetic newest',array_column($mixedHistory,'change'),true),'Settings history survives malformed reminder entries');
same($mixed,(clone $reminder)->value('audit'),'Reading mixed entries preserves the saved audit');
// Existing unreadable reminder history is not an empty history, and reading never repairs it.
$panel=\Filament\Facades\Filament::getCurrentPanel();
Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarHistory::registerRoutes($panel));
app('router')->getRoutes()->refreshNameLookups();
foreach(['broken ciphertext',encrypt('not an array'),encrypt(null)] as $unreadable){
    $reminder->update(['audit'=>$unreadable]);
    same(null,TldFacts::accountHistory($server),'Unreadable reminder history makes the combined history unreadable');
    \Livewire\Livewire::actingAs($u)->test(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarHistory::class,['account'=>$server])
        ->assertSee(\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::UNREADABLE)->assertDontSee('No changes yet');
    same($unreadable,(clone $reminder)->value('audit'),'Reading preserves the saved unreadable audit');
}
$reminder->update(['audit'=>encrypt([])]);
expect(is_array(TldFacts::accountHistory($server)),'An empty readable reminder audit keeps settings history');
$reminder->delete();expect(is_array(TldFacts::accountHistory($server)),'A missing reminder row keeps settings history');
Illuminate\Support\Facades\Schema::drop('domains_reminder_schedules');expect(is_array(TldFacts::accountHistory($server)),'A missing reminder table keeps settings history');
same(['Synthetic newest','Reminder emails turned off','Synthetic oldest','Synthetic invalid'],array_column($selected,'change'),'Parsed audit times sort newest first with invalid times last');
same(['zz-invalid-time','Readable retained detail'],[$selected[3]['time'],$selected[3]['reason']],'An invalid audit time retains its details');
$nativeSuccessful=true;echo "Staff account history names passed\n";
