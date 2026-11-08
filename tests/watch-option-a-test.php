<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-monitoring.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ViewWatch;
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
$errors=[];
$check=function(string $name,Closure $run)use(&$errors):void{try{$run();}catch(Throwable $e){$errors[]=$name.': '.$e->getMessage();}};
$check('No staff Stop action',function()use($manager,$watch):void{
    $page=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$watch->id]);
    same(null,$page->instance()->getAction('retire'),'Staff can pause, never stop a hosting watch');
});
$check('Writer refuses forged Stop',function()use($manager,$watch):void{
    $before=$watch->fresh()->getRawOriginal();$sources=$watch->sources()->get()->map->getRawOriginal()->all();$audit=\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count();$refused=false;
    try{(new Writes())->run($manager,'watch',(int)$watch->id,'retire',Writes::ticket((int)$manager->id,'watch',(int)$watch->id,(int)$watch->revision,'retire'),'Synthetic forbidden stop');}catch(DomainException){$refused=true;}
    expect($refused,'Direct staff retirement must be refused');same($before,$watch->fresh()->getRawOriginal(),'Refused Stop preserves watch');same($sources,$watch->sources()->get()->map->getRawOriginal()->all(),'Refused Stop preserves sources');same($audit,\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count(),'No Stop audit');
});
// The ordinary service lifecycle still owns retirement.
DB::transaction(fn()=>$service->update(['status'=>'cancelled']));
(new \Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryWork(static fn()=>null))->tick(time());
foreach($errors as$error){echo "FAIL $error\n";}
expect($errors===[],implode('; ',$errors));
$nativeSuccessful=true;echo "Monitoring Option A refuses staff Stop.\n";
