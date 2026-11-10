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
// Back-to-back system runs read as one row.
$rows=[['time'=>'2026-11-10T04:10:03+00:00','change'=>'Prices published','reason'=>'.org: Prices recalculated using the current pricing and sales settings.','by'=>'System','tld'=>'org'],
    ['time'=>'2026-11-10T04:10:02+00:00','change'=>'Prices published','reason'=>'.net: Prices recalculated using the current pricing and sales settings.','by'=>'System','tld'=>'net'],
    ['time'=>'2026-11-10T04:10:01+00:00','change'=>'Prices published','reason'=>'.com: Prices recalculated using the current pricing and sales settings.','by'=>'System','tld'=>'com'],
    ['time'=>'2026-11-10T04:09:00+00:00','change'=>'Price freshness changed','reason'=>'Staff change','by'=>'Staff #1']];
same([['time'=>'2026-11-10T04:10:03+00:00','change'=>'Prices published for 3 TLDs','reason'=>'.com, .net, .org: Prices recalculated using the current pricing and sales settings.','by'=>'System'],$rows[3]],TldFacts::group($rows),'Three system rows fold into one with a count, and the staff row stays near the top');
$nativeSuccessful=true;echo "Staff account history names passed\n";
