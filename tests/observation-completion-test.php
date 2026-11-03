<?php
// Synthetic completion boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationSchema,ObservationWork};
ObservationSchema::install(); $budget=new AccountReadBudget($clock);
[$d,$unused,$original]=$holding(placeholderComFixtureName('completion-holder-placeholder.com'));
$before=$original->fresh()->getAttributes();
$registry=new Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
$registry->register('enom',['token'],new class implements Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier {
    public function verify(Environment $e,array $c):string {return 'account-enom';}
},fn($b,$c)=>new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver(new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Client($b,'account-enom',$c['token'],$wire),['com']));
$readBindings=new Paymenter\Extensions\Servers\Domains\Services\AccountBindings($registry);
$work=new ObservationWork(new DomainObservation($readBindings,$clock,$budget),$clock,$budget); $work->enroll($d->server_id);
$afterProjection=null; $projected=false;
Domain::updated(static function(Domain $saved)use(&$afterProjection,&$projected,$d):void {
    if($saved->id!==$d->id || !$saved->wasChanged('observation') || !$afterProjection){return;}
    $hook=$afterProjection; $afterProjection=null;
    DB::afterCommit(static function()use($hook,&$projected):void {$projected=true; $hook();});
});
foreach(['rotation','generation'] as $scenario){
    DB::table('domains_observation_work')->where('domain_id',$d->id)->delete();
    $projected=false;
    $afterProjection=$scenario==='rotation'
        ? static fn()=>$readBindings->configure($d->server_id,'enom',Environment::Test,['token'=>'synthetic-completion-rotation'])
        : static function():void {(new UpgradeGate)->pause(); (new UpgradeGate)->resume();};
    $outcome=$work->tick(1)[$d->server_id];
    same(true,$projected,'The competing change happens after a committed projection');
    same('read_deferred',$outcome,$scenario.' after projection must refuse the completion outcome');
    $job=DB::table('domains_observation_work')->where('domain_id',$d->id)->sole();
    same(null,$job->safe_code,'A stale acknowledgement cannot record refreshed');
    same(null,$job->claim,'A refused completion releases its owned claim');
    same(0,(int)$job->lease_until,'A refused completion drains its lease');
    expect((int)$job->due_at<$clock->now()->getTimestamp()+86400,'No stale daily success postponement');
    same($before,$original->fresh()->getAttributes(),'Completion races preserve financial evidence');
    $clock->instant=$clock->now()->modify('+1 minute');
}
$nativeSuccessful=true;
echo "Holding completion credential and generation boundaries passed.\n";
