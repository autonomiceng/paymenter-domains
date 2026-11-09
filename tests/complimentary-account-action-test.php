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
// Actual SystemClock writes: the line shows the real instant of the change, even when Paymenter restores its PHP zone from settings and the app zone differs.
$priorTimezone=date_default_timezone_get();$priorAppZone=config('app.timezone');
try{
 foreach(['America/Los_Angeles','UTC']as$zone){date_default_timezone_set($zone);config(['app.timezone'=>$zone]);
  app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new \Paymenter\Extensions\Servers\Domains\Data\SystemClock);
  $on=(bool)(ComplimentaryAccounts::current($user->id)['enabled']??false);
  $start=time();$changed=$line($staff,$user->id)->callAction($on?'turnOff':'turnOn',['reason'=>'Storage timezone check'])->assertHasNoActionErrors();$end=time();
  expect(preg_match_all('/datetime="([^"]+)"/',$changed->html(),$shown)===1,'Actual action renders one change time: '.$zone);
  $at=new DateTimeImmutable($shown[1][0]);same('+00:00',$at->format('P'),'The change time is given in UTC: '.$zone);
  expect($start<=$at->getTimestamp()&&$at->getTimestamp()<=$end,'Actual action renders the real instant of the change: '.$zone);
 }
 foreach(['2026-07-01T12:00:00-07:00','2026-12-01T12:00:00-08:00']as$instant){
  date_default_timezone_set('America/Los_Angeles');config(['app.timezone'=>'America/Los_Angeles']);
  app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new class($instant) implements \Paymenter\Extensions\Servers\Domains\Contracts\Clock{public function __construct(private string $at){}public function now():DateTimeImmutable{return new DateTimeImmutable($this->at);}});
  $on=(bool)ComplimentaryAccounts::current($user->id)['enabled'];$changed=$line($staff,$user->id)->callAction($on?'turnOff':'turnOn',['reason'=>'Seasonal storage timestamp'])->assertHasNoActionErrors();
  expect(str_contains($changed->html(),'datetime="'.(new DateTimeImmutable($instant))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM).'"'),'Summer and winter retained timestamps keep their actual offset');
 }
 // The PHP zone is the one Paymenter restores from its settings; the app zone may differ. Each row keeps wall time in the PHP zone, whatever zone the clock answers in, and shows its real instant.
 $store=static fn(string $at)=>(new DateTimeImmutable($at))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
 foreach(['2026-11-02T12:30:00+09:00','2026-03-08T19:30:00+09:00']as$instant){
  config(['app.timezone'=>'America/Los_Angeles']);
  app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new \Paymenter\Extensions\Servers\Domains\Tests\FakeClock(new DateTimeImmutable($instant)));
  $on=(bool)ComplimentaryAccounts::current($user->id)['enabled'];$changed=$line($staff,$user->id)->callAction($on?'turnOff':'turnOn',['reason'=>'Clock zone differs'])->assertHasNoActionErrors();
  same($store($instant),ComplimentaryAccounts::current($user->id)['at'],'The row keeps PHP-zone wall time: '.$instant);
  expect(str_contains($changed->html(),'datetime="'.(new DateTimeImmutable($instant))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM).'"'),'The line shows the clock instant: '.$instant);
 }
 // Retained rows from before this change show their real instant on both sides of the autumn change and stay as written.
 foreach(['2026-11-01T07:30:00+00:00','2026-11-01T11:30:00+00:00']as$instant){
  config(['app.timezone'=>'America/Los_Angeles']);$wall=$store($instant);
  $id=DB::table('domains_complimentary_accounts')->insertGetId(['user_id'=>$user->id,'actor_id'=>$staff->id,'enabled'=>!ComplimentaryAccounts::current($user->id)['enabled'],'reason'=>'Retained change','created_at'=>$wall]);
  expect(str_contains($line($staff,$user->id)->html(),'datetime="'.$instant.'"'),'A retained row shows its real instant: '.$instant);
  same($wall,DB::table('domains_complimentary_accounts')->where('id',$id)->value('created_at'),'Showing a retained row leaves it as written');
 }
}finally{date_default_timezone_set($priorTimezone);config(['app.timezone'=>$priorAppZone]);app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);}
// Native audit disabled at provider boot means no listener; the extension still retains its history.
\Illuminate\Support\Facades\Event::forget(\OwenIt\Auditing\Events\AuditCustom::class);config(['audit.enabled'=>false]);
$on=(bool)ComplimentaryAccounts::current($user->id)['enabled'];
$noAudit=$line($staff,$user->id)->mountAction($on?'turnOff':'turnOn')->call('$refresh');
expect(str_contains(($noAudit->effects['partials']['action-modals.0']??'').$noAudit->html(),'Native audit logging is off'),'Confirmation explains the actual retained history');
$noAudit->setActionData(['reason'=>'Native audit disabled'])->callMountedAction()->assertHasNoActionErrors();
same('Native audit disabled',ComplimentaryAccounts::current($user->id)['reason'],'Disabled native auditing still retains the reason');
// With native auditing active, a storage failure must still roll back the extension setting.
config(['audit.enabled'=>true]);\Illuminate\Support\Facades\Event::listen(\OwenIt\Auditing\Events\AuditCustom::class,static fn()=>throw new RuntimeException('Synthetic audit failure'));
$before=$rows();$on=(bool)ComplimentaryAccounts::current($user->id)['enabled'];
try{ComplimentaryAccounts::set($user->id,$staff,!$on,'Refuse partial audit',$clock);expect(false,'Active audit failure propagates');}
catch(RuntimeException $e){same('Synthetic audit failure',$e->getMessage(),'Actual audit error propagates');}
same($before,$rows(),'Native audit failure rolls back the complimentary change');
echo "Complimentary account action permissions, validation, audit and repeat saves passed\n";$nativeSuccessful=true;
