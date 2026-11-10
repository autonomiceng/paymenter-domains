<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Account switches that reach customers ask first and say who the change reaches. The page click-through is in registrars-account-history-test.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{ReminderAction,SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Services\ReminderSchedules;
extract(commerceFixture(true));tldPanel();Carbon\Carbon::setTestNow($now);$server=(int)$tlds[0]->server_id;
Auth::setUser(staffUser([Staff::VIEW,Staff::POLICY]));
ReminderSchedules::enroll($server);
DB::table('domains_reminder_schedules')->where('server_id',$server)->update(['enabled'=>true]);
$on=ReminderAction::toggle($server);
expect($on->isConfirmationRequired(),'The Reminder emails switch asks first');
same(['Turn off reminder emails?','Turn off','Customers stop getting renewal reminder emails for this account’s domains until you turn this back on.'],
    [$on->getModalHeading(),$on->getModalSubmitActionLabel(),$on->getModalDescription()],'Turning reminders off says who it reaches');
DB::table('domains_reminder_schedules')->where('server_id',$server)->update(['enabled'=>false]);
$off=ReminderAction::toggle($server);
same(['Turn on reminder emails?','Turn on'],[$off->getModalHeading(),$off->getModalSubmitActionLabel()],'Turning reminders on asks first');
expect(str_starts_with((string)$off->getModalDescription(),'Customers start getting renewal reminder emails'),'Turning reminders on says who it reaches: '.$off->getModalDescription());
$popups=(new ReflectionClassConstant(SettingsActions::class,'POPUPS'))->getValue();
same(['DNS, nameservers and contacts','DNS, nameservers and contacts'],[$popups['test_management']??null,$popups['live_management']??null],'The DNS, nameservers and contacts switch asks first');
$warning=(new ReflectionMethod(SettingsActions::class,'switchWarning'))->invoke(null,'test_management',$server);
expect(str_contains($warning,'DNS records, nameservers'),'The DNS, nameservers and contacts question says what customers can change: '.$warning);
$nativeSuccessful=true;echo "Staff switch confirm passed\n";
