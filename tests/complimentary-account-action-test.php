<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The customer line's Livewire actions replace the old POST route: permission, validation, save, repeat saves and a missing customer.
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Support\ComplimentaryAccountSetting;
use Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts;
staffPanel();ComplimentaryAccountSetting::boot();
$staff=staffUser(['admin.domains.fund']);$plain=staffUser(['admin.users.update']);
$line=static fn($actor,int $target)=>Livewire::actingAs($actor)->test(ComplimentaryAccountSetting::class,['customer'=>$target]);
$rows=static fn()=>DB::table('domains_complimentary_accounts')->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all();
$refused=static function(callable $write,string $message):void {
    try{$write();}catch(DomainException $e){same($message,$e->getMessage(),'Refusal: '.$message);return;}
    throw new RuntimeException('Expected refusal: '.$message);
};
$before=$rows();
// Permission comes before the customer lookup: an existing and a missing customer look the same to staff without it.
foreach([$user->id,PHP_INT_MAX]as$target){
    $line($plain,$target)->assertDontSee('Standard domain prices')->assertActionHidden('turnOn')->assertActionHidden('turnOff');
    $refused(fn()=>ComplimentaryAccounts::set($target,$plain,true,'Unauthorised gift',app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)),'Domain funding permission required.');
}
same($before,$rows(),'Denied actions add no audit entries');
// Validation: a reason is required, at most 500 characters, and the state already in place offers no action and writes nothing.
foreach([['reason'=>'','error'=>'required'],['reason'=>'   ','error'=>'required'],['reason'=>str_repeat('r',501),'error'=>'max']]as$c){
    $line($staff,$user->id)->callAction('turnOn',['reason'=>$c['reason']])->assertHasActionErrors(['reason'=>$c['error']]);
}
$line($staff,$user->id)->assertActionVisible('turnOn')->assertActionHidden('turnOff');
ComplimentaryAccounts::set($user->id,$staff,false,'Invalid toggle',app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class),0);
same($before,$rows(),'Invalid actions add no audit entries');
$reason=str_repeat('界',500);
$line($staff,$user->id)->callAction('turnOn',['reason'=>$reason])->assertHasNoActionErrors()->assertSee('Complimentary domains');
same($reason,ComplimentaryAccounts::current($user->id)['reason'],'500 Unicode characters retain the whole audit reason');
$line($staff,$user->id)->callAction('turnOff',['reason'=>'Reset Unicode case'])->assertHasNoActionErrors();
$before=$rows();
// A valid save keeps the staff actor and reason and stays on the edited customer.
$line($staff,$user->id)->callAction('turnOn',['reason'=>'Customer gift'])->assertHasNoActionErrors()->assertNoRedirect()
    ->assertSet('customer',$user->id)->assertSee('Complimentary domains')->assertSee('Reason: Customer gift');
$current=ComplimentaryAccounts::current($user->id);same([$staff->id,true,'Customer gift'],[$current['actor'],$current['enabled'],$current['reason']],'The action retains the authenticated staff actor and reason');
same(count($before)+1,count($rows()),'A valid action appends exactly one audit entry');
// Repeating the enabled setting is idempotent: only Turn off is offered, and a same-state write over the seen row adds nothing.
$enabled=$rows();$clock=app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class);
$line($staff,$user->id)->assertActionHidden('turnOn')->assertActionVisible('turnOff');
ComplimentaryAccounts::set($user->id,$staff,true,'Repeated gift',$clock,$current['id']);same($enabled,$rows(),'Repeated setting is idempotent');
// Turning off disables future gifts; repeating it adds nothing.
$line($staff,$user->id)->callAction('turnOff',['reason'=>'Gift ended'])->assertHasNoActionErrors()->assertSee('Standard domain prices');
same([false,'Gift ended'],[ComplimentaryAccounts::current($user->id)['enabled'],ComplimentaryAccounts::current($user->id)['reason']],'Disable is recorded');
$disabled=$rows();
$line($staff,$user->id)->assertActionHidden('turnOff')->assertActionVisible('turnOn');
ComplimentaryAccounts::set($user->id,$staff,false,'Repeated end',$clock,ComplimentaryAccounts::current($user->id)['id']);same($disabled,$rows(),'Repeated disable adds no audit entry');
// A missing customer shows nothing and cannot gain an audit entry, even for staff with the permission.
$line($staff,PHP_INT_MAX)->assertDontSee('Standard domain prices')->assertActionHidden('turnOn')->assertActionHidden('turnOff');
$refused(fn()=>ComplimentaryAccounts::set(PHP_INT_MAX,$staff,true,'Unknown customer',$clock),'Domain funding permission required.');
same($disabled,$rows(),'Unknown customer cannot create an audit entry');
echo "Complimentary account action permissions, validation, audit and repeat saves passed\n";$nativeSuccessful=true;
