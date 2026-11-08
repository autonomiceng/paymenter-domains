<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-monitoring.php';
$clockKey=\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class;$originalClock=app($clockKey);
$testClock=new class(new DateTimeImmutable(gmdate('Y-m-d').'T23:59:59Z')) implements \Paymenter\Extensions\Servers\Domains\Contracts\Clock { public function __construct(public DateTimeImmutable $instant){} public function now():DateTimeImmutable{return $this->instant;} };
app()->instance($clockKey,$testClock);
try{
 extract($checked());$expectedDate=$expiry;
 foreach([0,2]as$seconds){
  $testClock->instant=$testClock->instant->modify('+'.$seconds.' seconds');
  $expected=intdiv(strtotime($expectedDate.' 00:00:00 UTC')-strtotime($testClock->now()->format('Y-m-d').' 00:00:00 UTC'),86400);
  $watch=\Paymenter\Extensions\Servers\Domains\Models\WatchName::findOrFail($id);
  $list=\Livewire\Livewire::actingAs($manager)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ListWatches::class);
  expect(str_contains($column($list,'note')->record($watch)->getState(),'Expires in '.$expected.' days.'),'List expiry uses the same clock before and after UTC midnight');
  $detail=\Livewire\Livewire::actingAs($manager)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ViewWatch::class,['record'=>$id]);
  $detail->assertSee('Expires in '.$expected.' days.');
 }
}finally{app()->instance($clockKey,$originalClock);}
$nativeSuccessful=true;echo "Staff expiry has one clock across UTC midnight.\n";
