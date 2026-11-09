<?php
// Synthetic completion boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
require __DIR__.'/Support/observation-work.php';
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
$work=observationFixtureScheduler(new DomainObservation($readBindings,$clock,$budget),$clock,$ports); $work->enroll($d->server_id);
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
        : static fn()=>advanceOfferGeneration();
    $outcome=$work->tick(2)[$d->server_id]['holding'];
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
// Manual Sync now and the scheduler share one lease: neither claims work the other holds.
$work=observationFixtureScheduler(new DomainObservation($readBindings,$clock,$budget),$clock,$ports);
DB::table('domains_observation_work')->where('domain_id',$d->id)->delete();
$lease=\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::claimManual($d->fresh(),$clock->now(),300);
expect(is_array($lease)&&$lease[0]>0&&strlen($lease[1])===32,'Manual sync queues and leases the holding read');
same('idle',$work->tick(2)[$d->server_id]['holding'],'The scheduler does not claim work under a live manual lease');
same(0,(int)DB::table('domains_observation_work')->where('id',$lease[0])->value('tries'),'A manual lease spends no scheduled try');
same(null,\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::claimManual($d->fresh(),$clock->now(),300),'A second claim waits for the live lease');
\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::releaseManual(...$lease);
expect($work->tick(2)[$d->server_id]['holding']!=='idle','The scheduler claims once the manual lease is released');
expect((int)DB::table('domains_observation_work')->where('id',$lease[0])->value('due_at')>$clock->now()->getTimestamp(),'The scheduled read finishes and sets its next due time');
$nativeSuccessful=true;
echo "Holding completion credential and generation boundaries passed.\n";
