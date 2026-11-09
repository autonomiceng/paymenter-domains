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
  $detail->assertSee('Expires in '.$expected.' days');
 }
 // Next check reads Checking now until the lease ends on the injected clock, then the next due time.
 $boundary=$testClock->now()->getTimestamp()+600;$due=$boundary+3600;
 \Illuminate\Support\Facades\DB::table('domains_watch_work')->where('watch_id',$id)->update(['claim'=>null,'lease_until'=>null,'backoff_until'=>null,'due_at'=>$due]);
 \Illuminate\Support\Facades\DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','delegation')->update(['claim'=>str_repeat('d',64),'lease_until'=>$boundary]);
 foreach([$boundary-1=>'Checking now',$boundary=>\Paymenter\Extensions\Servers\Domains\Admin\Support\WatchFacts::date($due)] as $at=>$next){
  $testClock->instant=new DateTimeImmutable('@'.$at);
  same($next,\Paymenter\Extensions\Servers\Domains\Admin\Support\WatchFacts::for(\Paymenter\Extensions\Servers\Domains\Models\WatchName::findOrFail($id))['Checks']['Next check']['value'],'Next check at lease end minus '.($boundary-$at).' seconds');
  $detail=\Livewire\Livewire::actingAs($manager)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ViewWatch::class,['record'=>$id]);
  same($next,$detail->instance()->getSchema('infolist')->getComponentByStatePath('fact_'.md5('Next check'))->getState(),'Page Next check at lease end minus '.($boundary-$at).' seconds');
 }
}finally{app()->instance($clockKey,$originalClock);}
same($originalClock,app($clockKey),'The real clock is restored');
$nativeSuccessful=true;echo "Staff expiry has one clock across UTC midnight.\n";
