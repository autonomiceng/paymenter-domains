<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Exact case effects are compared across processes, and no FakeClock is bound: keep the fixture boot instant.
$nativeFixedClock=true;
require __DIR__.'/Support/reminder-form-fixture.php';
require_once __DIR__.'/Support/fixture-cases.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{ReminderSchedules,StaffSettingsWrites};
// Every case starts from the same enrolled reminder schedule, policy staff member and mounted account page, and makes
// real successful or refused setting writes through the form and the write service.
$nativeSuccessful=false;
// Pin each case to the prepared Carbon clocks explicitly, whatever a reset restores.
$pinned=[Carbon\Carbon::getTestNow(),Carbon\CarbonImmutable::getTestNow()];
$pin=static function()use($pinned):void{[$native,$immutable]=$pinned;Carbon\Carbon::setTestNow($native);Carbon\CarbonImmutable::setTestNow($immutable);};
$fixed=compact('u','server','query','original','name','placeholder','pin');

// Malformed reminder days.
$cases['malformed']=static function()use($fixed):void {extract($fixed);
    $pin();same($original,(array)(clone $query)->sole(),'Each case starts from the enrolled reminder schedule');
// Every stored JSON shape exercises the parser and locked write guard.
// Each field also traverses the real mounted form, one with each parser failure class.
foreach(['before'=>'{','after'=>'["7"]'] as $field=>$mounted){
    foreach(['{','null','true','7','"7"','{}','{"day":7}','[[7]]','["7"]','[-1]','[366]','[1.5]'] as $bad){
        same(null,ReminderSchedules::storedDays($bad),'Unreadable saved days: '.$bad);
        $query->update([...$original,$field.'_days'=>$bad]);$before=(array)(clone $query)->sole();
        commerceReject(fn()=>ReminderSchedules::save($u,$server,(int)$original['revision'],true,'7','3','Refuse unreadable saved days'));
        same($before,(array)(clone $query)->sole(),'Malformed days and audit are never rewritten: '.$bad);
    }
    $query->update([...$original,$field.'_days'=>$mounted]);$before=(array)(clone $query)->sole();
    $page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->assertSuccessful()->mountAction($name)->assertSuccessful()->assertActionMounted($name);
    same(null,$page->get('mountedActions.0.data.'.$field),'Unreadable days have no invented default: '.$mounted);
    $page->assertSee('Missing')->assertSee("Saved reminder days can't be read.");
    same('Missing',$placeholder($page,$field),'Unreadable day control explains its state');
    $page->setActionData(['enabled'=>true,'before'=>'7','after'=>'3','reason'=>'Refuse unreadable saved days'])->callMountedAction()->assertHasNoActionErrors()->assertNotified("Couldn't save reminders");
    same($before,(array)(clone $query)->sole(),'Malformed days and audit are never rewritten: '.$mounted);
}
};

// Reminder writes recheck saved days under the write lock and save a valid schedule.
$cases['write']=static function()use($fixed):void {extract($fixed);
    $pin();same($original,(array)(clone $query)->sole(),'Each case starts from the enrolled reminder schedule');
// Recheck retained data under the write lock, including corruption after a valid mount.
$page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->mountAction($name)->assertActionMounted($name);
same((int)$original['revision'],(int)$page->get('mountedActions.0.data.revision'),'A valid mount carries the saved revision');
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
};

// Reminder recovery and permission refusals.
$cases['recovery']=static function()use($fixed):void {extract($fixed);
    $pin();same($original,(array)(clone $query)->sole(),'Each case starts from the enrolled reminder schedule');
// A repaired schedule still needs a fresh form: its saved days were never shown.
$query->update([...$original,'before_days'=>'{']);
$page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->mountAction($name)->assertActionMounted($name);
$query->update($original);
$page->setActionData(['enabled'=>true,'before'=>'7','after'=>'3','reason'=>'Refuse facts not displayed'])->callMountedAction()->assertHasNoActionErrors()->assertNotified("Couldn't save reminders");
same($original,(array)(clone $query)->sole(),'Repair after mounting cannot save unseen days');
// Unreadable mounts remain invalid even if revision zero is repaired during a later request.
// The before field's repair after mounting is the case above; this one repairs the after field.
$recovery='Reload the account page, then check the reminder days and your permissions before saving again.';
$edit=['enabled'=>true,'before'=>'7','after'=>'3','reason'=>'Review fresh reminder facts'];
$zero=[...$original,'revision'=>0];$query->update([...$zero,'after_days'=>'{']);
$page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->mountAction($name)->assertActionMounted($name);
same(null,$page->get('mountedActions.0.data.revision'),'Unreadable mount has no reviewed revision');
$query->update($zero);$page->call('$refresh');
$page->setActionData($edit)->callMountedAction()->assertHasNoActionErrors()
    ->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't save reminders")->body($recovery));
same($zero,(array)(clone $query)->sole(),'Repair at revision zero does not authorize unseen saved days');
Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->mountAction($name)->setActionData($edit)->callMountedAction()->assertNotified('Reminders saved');
same(1,(int)(clone $query)->sole()->revision,'A readable revision-zero remount can save');
foreach(['stale','permission','days','account'] as $failure){
    $query->update($original);$page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->mountAction($name)->assertActionMounted($name);
    $data=$edit;
    if($failure==='stale'){$query->update(['revision'=>(int)$original['revision']+1]);}
    if($failure==='account'){$query->update(['account_fingerprint'=>str_repeat('a',64)]);}
    if($failure==='days'){$data['before']='400';}
    $beforeFailure=(array)(clone $query)->sole();
    if($failure==='permission'){
        $callback=$page->instance()->getMountedAction()->getActionFunction();$u->role()->first()->update(['permissions'=>[Staff::VIEW]]);
        $callback([...$data,'revision'=>(int)$original['revision']]);
        Filament\Notifications\Notification::assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't save reminders")->body($recovery));
        $u->role()->first()->update(['permissions'=>[Staff::VIEW,Staff::POLICY]]);
    }elseif($failure==='days'){
        // A bad day is named under its field and the dialog keeps what was typed.
        $page->setActionData($data)->callMountedAction()->assertHasActionErrors(['before'])->assertActionMounted($name);same('400',$page->get('mountedActions.0.data.before'),'The dialog keeps the typed days');
    }else{$page->setActionData($data)->callMountedAction()->assertHasNoActionErrors()->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't save reminders")->body($recovery));}
    same($beforeFailure,(array)(clone $query)->sole(),'Refusal retains schedule and audit: '.$failure);
}
$query->update($original);
};
nativeFixtureCases($cases,compact('u','server','original'));
// Dropping the optional table cannot be restored by the case reset, so this check runs once, after every case.
// View-only and policy staff retain the account page when optional storage is absent.
Illuminate\Support\Facades\Schema::drop('domains_reminder_schedules');
foreach([[Staff::VIEW],[Staff::VIEW,Staff::POLICY]] as $rights){
    $viewer=staffUser($rights);
    Livewire::actingAs($viewer)->test(RegistrarAccount::class,['account'=>$server])->assertSuccessful()->assertDontSee('Edit reminders');
}
echo "Malformed reminder days, writes, recovery, permissions and unavailable storage checks passed.\n";$nativeSuccessful=true;
