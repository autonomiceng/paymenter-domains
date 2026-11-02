<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use App\Models\Server;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestSendLimit,StaffSettingsReview as Review,StaffSettingsWrites};
extract(commerceFixture(true));tldPanel();Carbon\Carbon::setTestNow($now);$server=(int)$tlds[0]->server_id;
$u=staffUser([Staff::VIEW,Staff::POLICY,Review::permission('call_limits')]);Auth::setUser($u);
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
config(['app.debug'=>true,'domains.enom_test_sends_per_day'=>1000,'domains.enom_test_sends_per_minute'=>60]);
$uid=(string)Account::where('server_id',$server)->sole()->stable_identity;
$name=TestAction::make('call_limits_'.$server)->schemaComponent(true,'content');
$edit=static fn()=>Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$server])
    ->assertSuccessful()->mountAction($name)->assertActionMounted($name);
$page=$edit()->assertActionDataSet(['day'=>1000,'minute'=>60]);
// Browser inputs arrive as strings; submitting must include Filament's validation and state casts.
$page->setActionData(['day'=>'2000','minute'=>'61','reason'=>'Adjust our synthetic call limits'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Call limits saved');
same(['day'=>2000,'minute'=>61],EnomTestSendLimit::limits($uid),'The physical guard reads limits saved through the real form');
$history=Review::historyRows(Server::findOrFail($server));$saved=end($history);
same(['day'=>1000,'minute'=>60],$saved['before']['call_limits'],'Form save audits the prior limits');
same(['day'=>2000,'minute'=>61],$saved['after']['call_limits'],'Form save audits whole-number limits');
$page=$edit()->assertActionDataSet(['day'=>2000,'minute'=>61]);
same(['Cancel','Save'],array_values(array_map(fn($action)=>$action->getLabel(),$page->instance()->getMountedAction()->getModalFooterActions())),
    'Cancel precedes Save in the real modal footer');
$page->setActionData(['day'=>'3000','minute'=>'62','reason'=>'Cancel synthetic limit changes'])->unmountAction();
same(['day'=>2000,'minute'=>61],EnomTestSendLimit::limits($uid),'Cancel leaves saved limits alone');
// Integer normalization must happen after validation, without rounding or truncating invalid inputs.
foreach(['day','minute'] as $field){
    foreach(['0'=>'min','100001'=>'max','61.5'=>'integer'] as $bad=>$rule){
        $edit()->setActionData(['day'=>'2000','minute'=>'61','reason'=>'Refuse invalid synthetic limits',$field=>(string)$bad])
            ->callMountedAction()->assertHasActionErrors([$field=>$rule]);
        same(['day'=>2000,'minute'=>61],EnomTestSendLimit::limits($uid),'Invalid form input cannot change saved limits');
    }
}
echo "Call limit form saves browser values through the real mounted action.\n";$nativeSuccessful=true;
