<?php
// Enom d1392a8 grace and covered renewal observation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// This claim matrix exercises Enom only; do not build an unused NameSilo account.
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{RenewalCode, RenewalRequest};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, EnomLateRenewal, EnomTestRenewal, EnomTestSendLimitSchema, RenewalRefused};
// Native invoices, funding, dispatch seals and real reconciliation are required for claim safety.
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-late-ack-placeholder.com')); [$engine,$writer]=$compose($b); $writes=$state->writes;
$state->afterWrite=static function()use($clock,$engine,$op,$writer){ $clock->instant=$clock->now()->modify('+361 seconds'); same('uncertain',$engine->run($op->id,$writer)->state,'Expired running claim quarantined without replay'); };
same('uncertain',$engine->run($op->id,$writer)->state,'Late acknowledgement cannot restore a quarantined claim');
same($writes+1,$state->writes,'Stale and late invocations share one write');
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-concurrent-placeholder.com')); [$engine,$writer]=$compose($b); $writes=$state->writes;
$state->during=static function()use($engine,$writer,$op){
    $before=$op->fresh()->getRawOriginal(); $engine->run($op->id,$writer); same($before,$op->fresh()->getRawOriginal(),'Concurrent run cannot change owned preflight claim');
};
same('pending',$engine->run($op->id,$writer)->state,'Original preflight survives concurrent invocation'); same($writes+1,$state->writes,'Concurrent workers share one send');
[$op,$d,$b,$bill]=$makeRenewal(placeholderComFixtureName('renew-prewire-placeholder.com')); [$engine,$writer]=$compose($b); $writes=$state->writes;
Paymenter\Extensions\Servers\Domains\Models\Operation::updated(static function($row)use($op,$bill){
    if($row->id===$op->id && $row->state==='running'){ Illuminate\Support\Facades\DB::table('invoices')->where('id',$bill->id)->update(['status'=>'cancelled']); }
});
same('held',$engine->run($op->id,$writer)->state,'Changed sealed authority retains positive prewire refusal');
same($writes,$state->writes,'Known prewire refusal never enters exchange'); same('definitely_unsent',$op->fresh()->attempt['execution']['send_state'],'Typed definitely-unsent evidence retained');
$engine->run($op->id,$writer); same($writes,$state->writes,'Sealed prewire refusal cannot replay');
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-postsend-cancel-placeholder.com')); [$engine,$writer,$completion]=$compose($b); $engine->run($op->id,$writer);
App\Models\ServiceCancellation::create(['service_id'=>$d->service_id,'type'=>'end_of_period','reason'=>'Synthetic']);
$state->expiry='2028-10-18'; $state->charge='8.00'; same('succeeded',$completion->run($op->id)->state,'Future-only cancellation does not strand original paid reconciliation');
same('2028-10-18',$d->fresh()->provider_expires_at->format('Y-m-d'),'Original registrar proof advances owned expiry');same('active',$d->service->fresh()->status,'Registrar reconciliation preserves native paid status');
// An old lost renewal reply can use an audited support order, followed by exact independent readback.
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-support-answer-placeholder.com'));[$engine,$writer,$completion]=$compose($b);
$state->lost=true;$writes=$state->writes;same('uncertain',$engine->run($op->id,$writer)->state,'Missing reply remains uncertain');$attempt=$op->fresh()->attempt;
$clock->instant=$clock->now()->modify('+25 hours');$state->lost=false;$state->expiry='2028-10-18';$state->charge='8.00';
// The answer needs Enom's real reader, but later commerce still owns both enrolled accounts.
$fixtureBindings=app(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class);
try {
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);app()->instance(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,$renewBindings);
$staff=commerceUser();$role=\App\Models\Role::create(['name'=>'Renewal support answer','permissions'=>['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.enom_answer']]);$staff->update(['role_id'=>$role->id]);
$reviewClass=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::class;
$review=new $reviewClass($staff->id,$op->server_id,null,'enom_answer',$reviewClass::ticket($staff->id,$op->server_id,null,'enom_answer',$reviewClass::stamp($op->server_id,null)),'Record exact synthetic renewal answer');
(new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites($native,$sellers,$renewBindings))->run($review,['operation_id'=>$op->id,
    'attempt_hash'=>\Paymenter\Extensions\Servers\Domains\Services\EnomAnswers::identity($op->fresh()),'outcome'=>'accepted','order_reference'=>'9002','case_reference'=>'synthetic-renewal-case','evidence'=>'Exact account and sealed renewal attempt were processed as order 9002.','confirmed'=>true],$clock->now());
same('succeeded',$completion->run($op->id)->state,'Supported renewal settles only through exact charge and expiry proof');
same($attempt,array_diff_key($op->fresh()->attempt,['reconciliation'=>true]),'Original sealed renewal evidence remains unchanged');
expect(\Paymenter\Extensions\Servers\Domains\Services\RenewalAuthority::completed($op->fresh(),$d->fresh(),$b),'Staff-supported renewal proof anchors the next cycle');
same($writes+1,$state->writes,'Support answer and reconciliation never send another renewal');
} finally { app()->instance(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,$fixtureBindings); }
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-below-cost-placeholder.com'),true,1,false,static function($d)use($clock,$ports){
    $binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$d->server_id)->sole());
    $q=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($binding,[$d->tld],'renew');
    $ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::retainRenewal($binding,$d->tld,'1.00'),$ports->readOffers($q,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision);
});
same('1.00',$op->amount,'Below-cost renewal billed at exact retail with no floor');
[$engine,$writer,$completion]=$compose($b); same('pending',$engine->run($op->id,$writer)->state,'Below-cost paid renewal still sends');
$state->expiry='2028-10-18'; $state->charge='8.00';
same('succeeded',$completion->run($op->id)->state,'Below-cost outcome completes');
same('below_cost',$op->fresh()->policy['renewal_execution']['reason'],'Below-cost outcome alerts staff');
echo "Renewal claim safety, staff answer and below-cost completion passed\n"; $nativeSuccessful=true;
