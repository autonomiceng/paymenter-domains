<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reminder-form-fixture.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{ReminderSchedules,StaffSettingsWrites};
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
    }elseif($failure==='days'){
        // A bad day is named under its field and the dialog keeps what was typed.
        $page->setActionData($data)->callMountedAction()->assertHasActionErrors(['before'])->assertActionMounted($name);same('400',$page->get('mountedActions.0.data.before'),'The dialog keeps the typed days');
    }else{$page->setActionData($data)->callMountedAction()->assertHasNoActionErrors()->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't save reminders")->body($recovery));}
    same($beforeFailure,(array)(clone $query)->sole(),'Refusal retains schedule and audit: '.$failure);
}
$query->update($original);

echo "Reminder recovery and permission checks passed.\n";$nativeSuccessful=true;
