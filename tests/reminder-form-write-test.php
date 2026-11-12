<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reminder-form-fixture.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{ReminderSchedules,StaffSettingsWrites};
// Recheck retained data under the write lock, including corruption after a valid mount.
$page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->mountAction($name)->assertActionMounted($name);
$query->update(['after_days'=>'[{}]']);$before=(array)(clone $query)->sole();
$page->setActionData(['enabled'=>true,'before'=>'7','after'=>'3','reason'=>'Refuse changed saved days'])->callMountedAction()->assertHasNoActionErrors()->assertNotified("Couldn't save reminders");
same($before,(array)(clone $query)->sole(),'Corruption after mounting changes no saved facts');
commerceReject(fn()=>ReminderSchedules::save($u,$server,(int)$original['revision'],true,'7','3','Refuse malformed saved days'));
same($before,(array)(clone $query)->sole(),'Write service preserves unreadable days and audit');
$query->update([...$original,'before_days'=>'[]','after_days'=>'[0,365]']);
$page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->mountAction($name)->assertActionMounted($name);
same('',$page->get('mountedActions.0.data.before'),'Empty saved list is readable');
same('0,365',$page->get('mountedActions.0.data.after'),'Inclusive day limits are readable');
same(null,$placeholder($page,'before'),'Empty saved list is not labeled unavailable');
$query->update($original);
$page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->assertSuccessful()->mountAction($name)->assertSuccessful()->assertActionMounted($name);
same('1,7,30,60',$page->get('mountedActions.0.data.before'),'Valid stored days populate the form');
$page->setActionData(['enabled'=>true,'before'=>'7,30','after'=>'3','reason'=>'Review valid saved reminders'])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Reminders saved');
same('[7,30]',(clone $query)->sole()->before_days,'Valid schedule remains editable');
// View-only and policy staff retain the account page when optional storage is absent.
Illuminate\Support\Facades\Schema::drop('domains_reminder_schedules');
foreach([[Staff::VIEW],[Staff::VIEW,Staff::POLICY]] as $rights){
    $viewer=staffUser($rights);
    Livewire::actingAs($viewer)->test(RegistrarAccount::class,['account'=>$server])->assertSuccessful()->assertDontSee('Edit reminders');
}
echo "Reminder write and unavailable storage checks passed.\n";$nativeSuccessful=true;
