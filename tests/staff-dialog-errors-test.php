<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// A bad value in a registrar account dialog is named under its field; the dialog stays open with what was typed.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{ReminderSchedules,StaffSettingsReview as Review,StaffSettingsWrites};
extract(commerceFixture(true));tldPanel();Carbon\Carbon::setTestNow($now);$server=(int)$tlds[0]->server_id;
$u=staffUser([Staff::VIEW,Staff::POLICY,Review::permission('renewal_billing')]);Auth::setUser($u);
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
ReminderSchedules::enroll($server);$saved=(array)DB::table('domains_reminder_schedules')->where('server_id',$server)->sole();
$reminders=setupAction('reminders',$server);
$page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->mountAction($reminders)->assertActionMounted($reminders);
$page->setActionData(['before'=>'7, abc','after'=>'3','reason'=>'Check a bad reminder day'])->callMountedAction()
    ->assertHasActionErrors(['before'])->assertActionMounted($reminders);
same('"abc" is not a day from 0 to 365.',$page->errors()->first('mountedActions.0.data.before'),'The bad day is named under its field');
same(['7, abc','3'],[$page->get('mountedActions.0.data.before'),$page->get('mountedActions.0.data.after')],'The reminder dialog keeps what was typed');
same($saved,(array)DB::table('domains_reminder_schedules')->where('server_id',$server)->sole(),'A bad day saves nothing');
$schedule=setupAction('renewal_billing',$server);
$page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->mountAction($schedule)->assertActionMounted($schedule);
$page->setActionData(['issue_days'=>'7','due_days'=>'10','reason'=>'Check issue days before due days'])->callMountedAction()
    ->assertHasActionErrors(['issue_days'])->assertActionMounted($schedule);
same('Must be more than the due days (10).',$page->errors()->first('mountedActions.0.data.issue_days'),'Issue days name the due days under the field');
same(['7','10'],[(string)$page->get('mountedActions.0.data.issue_days'),(string)$page->get('mountedActions.0.data.due_days')],'The schedule dialog keeps what was typed');
$nativeSuccessful=true;echo "Staff dialog errors passed\n";
