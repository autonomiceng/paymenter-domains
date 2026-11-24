<?php
// Enom d1392a8 grace and covered renewal observation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{RenewalCode, RenewalRequest};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, EnomLateRenewal, EnomTestRenewal, EnomTestSendLimitSchema, RenewalRefused};
// Native funding, claim ownership and original support answers require the shared financial fixture.
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
$warnings = [];
app('events')->listen(Illuminate\Log\Events\MessageLogged::class, static function ($event) use (&$warnings): void {
    if ($event->message === 'Domains renewal reply not recorded.') { $warnings[] = [$event->level, $event->context]; }
});
$state->lost=true;$writes=$state->writes;same('uncertain',$engine->run($op->id,$writer)->state,'Missing reply remains uncertain');$attempt=$op->fresh()->attempt;
expect(!array_key_exists('send_failure', $attempt), 'Unclassified failure retains no invented reason');
same([['warning', ['operation_id' => $op->id, 'exception_class' => RuntimeException::class]]], $warnings, 'Unclassified failure logs only its type');
$clock->instant=$clock->now()->modify('+25 hours');$state->lost=false;$state->expiry='2028-10-18';$state->charge='8.00';
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);app()->instance(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,$renewBindings);
// Enom reads the domain on a lost reply before staff record its answer. The read alone cannot complete the renewal.
same('uncertain',$completion->run($op->id)->state,'A read without the answer keeps the renewal uncertain');
$staff=commerceUser();$role=\App\Models\Role::create(['name'=>'Renewal support answer','permissions'=>['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.enom_answer']]);$staff->update(['role_id'=>$role->id]);
$reviewClass=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::class;
$review=new $reviewClass($staff->id,$op->server_id,null,'enom_answer',$reviewClass::ticket($staff->id,$op->server_id,null,'enom_answer',$reviewClass::stamp($op->server_id,null)),'Record exact synthetic renewal answer');
(new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites($native,$sellers,$renewBindings))->run($review,['operation_id'=>$op->id,
    'attempt_hash'=>\Paymenter\Extensions\Servers\Domains\Services\EnomAnswers::identity($op->fresh()),'outcome'=>'accepted','order_reference'=>'9002','case_reference'=>'synthetic-renewal-case','evidence'=>'Exact account and sealed renewal attempt were processed as order 9002.','confirmed'=>true],$clock->now());
same('succeeded',$completion->run($op->id)->state,'Supported renewal settles only through exact charge and expiry proof');
same($attempt,array_diff_key($op->fresh()->attempt,['reconciliation'=>true]),'Original sealed renewal evidence remains unchanged');
expect(\Paymenter\Extensions\Servers\Domains\Services\RenewalAuthority::completed($op->fresh(),$d->fresh(),$b),'Staff-supported renewal proof anchors the next cycle');
same($writes+1,$state->writes,'Support answer and reconciliation never send another renewal');
echo "Renewal claim safety and staff answer passed\n"; $nativeSuccessful=true;
