<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{ReminderSchedules,StaffSettingsWrites};
extract(commerceFixture(true));tldPanel();Carbon\Carbon::setTestNow($now);$server=(int)$tlds[0]->server_id;
$u=staffUser([Staff::VIEW,Staff::POLICY]);Auth::setUser($u);
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
config(['app.debug'=>true]);
ReminderSchedules::enroll($server);$query=DB::table('domains_reminder_schedules')->where('server_id',$server);
$original=(array)(clone $query)->sole();$name=setupAction('reminders',$server);
$placeholder=static fn($page,string $key)=>$page->instance()->getSchema('mountedActionSchema0')->getComponent(fn($field)=>$field instanceof Filament\Forms\Components\TextInput&&$field->getName()===$key)->getPlaceholder();
// Each stored JSON shape is exercised through the real page and mounted action.
foreach(['before','after'] as $field){
    foreach(['{','null','true','7','"7"','{}','{"day":7}','[[7]]','["7"]','[-1]','[366]','[1.5]'] as $bad){
        $query->update([...$original,$field.'_days'=>$bad]);$before=(array)(clone $query)->sole();
        $page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->assertSuccessful()->mountAction($name)->assertSuccessful()->assertActionMounted($name);
        same(null,$page->get('mountedActions.0.data.'.$field),'Unreadable days have no invented default: '.$bad);
        $page->assertSee('Unavailable')->assertSee('Saved reminder days cannot be read.');
        same('Unavailable',$placeholder($page,$field),'Unreadable day control explains its state');
        $page->setActionData(['enabled'=>true,'before'=>'7','after'=>'3','reason'=>'Refuse unreadable saved days'])->callMountedAction()->assertHasNoActionErrors()->assertNotified("Couldn't save reminders");
        same($before,(array)(clone $query)->sole(),'Malformed days and audit are never rewritten: '.$bad);
    }
}
// A repaired schedule still needs a fresh form: its saved days were never shown.
$query->update([...$original,'before_days'=>'{']);
$page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->mountAction($name)->assertActionMounted($name);
$query->update($original);
$page->setActionData(['enabled'=>true,'before'=>'7','after'=>'3','reason'=>'Refuse facts not displayed'])->callMountedAction()->assertHasNoActionErrors()->assertNotified("Couldn't save reminders");
same($original,(array)(clone $query)->sole(),'Repair after mounting cannot save unseen days');
// Unreadable mounts remain invalid even if revision zero is repaired during a later request.
$recovery='Reload the account page, then check the reminder days and your permissions before saving again.';
$edit=['enabled'=>true,'before'=>'7','after'=>'3','reason'=>'Review fresh reminder facts'];
foreach(['before','after'] as $field){
    $zero=[...$original,'revision'=>0];$query->update([...$zero,$field.'_days'=>'{']);
    $page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->mountAction($name)->assertActionMounted($name);
    same(null,$page->get('mountedActions.0.data.revision'),'Unreadable mount has no reviewed revision');
    $query->update($zero);$page->call('$refresh');
    $page->setActionData($edit)->callMountedAction()->assertHasNoActionErrors()
        ->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't save reminders")->body($recovery));
    same($zero,(array)(clone $query)->sole(),'Repair at revision zero does not authorize unseen saved days');
    Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->mountAction($name)->setActionData($edit)->callMountedAction()->assertNotified('Reminders saved');
    same(1,(int)(clone $query)->sole()->revision,'A readable revision-zero remount can save');
}
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
    }else{$page->setActionData($data)->callMountedAction()->assertHasNoActionErrors()->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't save reminders")->body($recovery));}
    same($beforeFailure,(array)(clone $query)->sole(),'Refusal retains schedule and audit: '.$failure);
}
$query->update($original);

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
echo "Reminder action mount and retained malformed days passed.\n";$nativeSuccessful=true;
