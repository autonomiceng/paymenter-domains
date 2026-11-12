<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reminder-form-fixture.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{ReminderSchedules,StaffSettingsWrites};
// Every stored JSON shape exercises the parser and locked write guard.
// The two parser failure classes also traverse the real mounted form for each field.
foreach(['before','after'] as $field){
    foreach(['{','null','true','7','"7"','{}','{"day":7}','[[7]]','["7"]','[-1]','[366]','[1.5]'] as $bad){
        same(null,ReminderSchedules::storedDays($bad),'Unreadable saved days: '.$bad);
        $query->update([...$original,$field.'_days'=>$bad]);$before=(array)(clone $query)->sole();
        commerceReject(fn()=>ReminderSchedules::save($u,$server,(int)$original['revision'],true,'7','3','Refuse unreadable saved days'));
        same($before,(array)(clone $query)->sole(),'Malformed days and audit are never rewritten: '.$bad);
    }
    foreach(['{','["7"]'] as $bad){
        $query->update([...$original,$field.'_days'=>$bad]);$before=(array)(clone $query)->sole();
        $page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])->assertSuccessful()->mountAction($name)->assertSuccessful()->assertActionMounted($name);
        same(null,$page->get('mountedActions.0.data.'.$field),'Unreadable days have no invented default: '.$bad);
        $page->assertSee('Missing')->assertSee("Saved reminder days can't be read.");
        same('Missing',$placeholder($page,$field),'Unreadable day control explains its state');
        $page->setActionData(['enabled'=>true,'before'=>'7','after'=>'3','reason'=>'Refuse unreadable saved days'])->callMountedAction()->assertHasNoActionErrors()->assertNotified("Couldn't save reminders");
        same($before,(array)(clone $query)->sole(),'Malformed days and audit are never rewritten: '.$bad);
    }
}
echo "Malformed reminder days passed.\n";$nativeSuccessful=true;
