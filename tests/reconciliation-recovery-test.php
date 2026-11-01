<?php
// Slice21 double-persistence residual; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/reconciliation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\HistoryAcceptances;
$acceptedWriter = new class($writer) implements \Paymenter\Extensions\Servers\Domains\Contracts\AdmittedRegistrarDriver {
    public function __construct(private $inner) {}
    public function register($admitted, $context): \Paymenter\Extensions\Servers\Domains\Data\DriverResult {
        $this->inner->register($admitted, $context);
        return new \Paymenter\Extensions\Servers\Domains\Data\DriverResult($admitted->binding,
            \Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted, \Paymenter\Extensions\Servers\Domains\Data\SendState::Sent,
            \Paymenter\Extensions\Servers\Domains\Data\ResultReason::Acknowledged,
            new \Paymenter\Extensions\Servers\Domains\Data\Evidence(registration: new \Paymenter\Extensions\Servers\Domains\Data\RegistrationEvidence($admitted->query, '9001', null, null, [])));
    }
    public function renew($op, $ctx): \Paymenter\Extensions\Servers\Domains\Data\DriverResult { throw new LogicException; }
    public function transferIn($op, $ctx): \Paymenter\Extensions\Servers\Domains\Data\DriverResult { throw new LogicException; }
};
$crashEngine = new \Paymenter\Extensions\Servers\Domains\Services\DomainOperations($claims, $preflight, $acceptedWriter, $clock); $writer->engine = $crashEngine;
foreach (['unique', 'zero', 'multiple'] as $case) {
    $clock->instant = new DateTimeImmutable('2026-10-18T12:00:00Z');
    $op = $new(placeholderComFixtureName('lost-completion-'.$case.'-placeholder.com')); $failures = 0;
    Operation::updating(static function ($row) use ($op, &$failures): void {
        if ($row->id === $op->id && array_key_exists('execution', $row->attempt ?? [])) {
            $failures++; throw new RuntimeException('Synthetic returned-result persistence failure');
        }
    });
    $before = $writer->calls;
    try { $crashEngine->execute($op->id); expect(false, 'Both completion writes fail'); } catch (RuntimeException) {}
    same(2, $failures, 'One same-result persistence retry, without another registrar send');
    same($before + 1, $writer->calls, 'Exactly one original send');
    $op = $op->fresh(); same('sealed', $op->attempt['seal'], 'Original seal survives both rollbacks');
    expect(!array_key_exists('execution', $op->attempt), 'No returned execution facts persisted');
    $binding = \Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $op->server_id)->sole());
    HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'enom_registration_v1'));
    $observation->name = $op->domain->domain; $observation->fault = $case === 'unique' ? '' : $case;
    $observation->calls = []; $clock->instant = new DateTimeImmutable('2026-10-18T12:06:01Z');
    $result = $reconcile->run($op->id);
    same($case === 'unique' ? 'succeeded' : 'uncertain', $result->state, 'Provider-only original-attempt recovery requires one proven charge');
    same($before + 1, $writer->calls, 'Recovery never replays original send');
    same(false, array_key_exists('execution', $result->attempt), 'Recovery preserves absence of returned result');
    if ($case !== 'unique') { same(null, $result->domain->provider_expires_at, 'Ambiguity never anchors ownership'); }
}
// A support answer after the bounded history window only unlocks exact reads or one payment return.
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,$bindings);
$staff=commerceUser();$permissions=['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.enom_answer','admin.domains.manage','admin.invoices.update'];
$role=\App\Models\Role::create(['name'=>'Support answers','permissions'=>$permissions]);$staff->update(['role_id'=>$role->id]);
$settings=new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites($native,$sellers,$bindings);
$review=static fn($op)=>new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview($staff->id,$op->server_id,null,'enom_answer',
    \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::ticket($staff->id,$op->server_id,null,'enom_answer',\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::stamp($op->server_id,null)),'Record final synthetic Enom answer');
$input=static fn($op,$outcome)=>['operation_id'=>$op->id,'attempt_hash'=>\Paymenter\Extensions\Servers\Domains\Services\EnomAnswers::identity($op),
    'outcome'=>$outcome,'order_reference'=>$outcome==='accepted'?'9001':'','case_reference'=>'synthetic-case-123','evidence'=>'Enom checked the exact account, domain and attempt time. Final answer as selected.','confirmed'=>true];
foreach(['accepted','not_processed'] as $outcome){
    [$op]=$prepare(placeholderComFixtureName('support-'.str_replace('_','-',$outcome).'-placeholder.com'),'absent');$attempt=$op->attempt;$before=$writer->calls;
    commerceReject(fn()=>$settings->run($review($op),$input($op,$outcome),$clock->now()));
    $clock->instant=$clock->now()->modify('+25 hours');$r=$review($op);
    $bad=$input($op,$outcome);$bad['attempt_hash']=str_repeat('0',64);commerceReject(fn()=>$settings->run($r,$bad,$clock->now()));
    $role->update(['permissions'=>array_diff($permissions,['admin.domains.settings.enom_answer'])]);commerceReject(fn()=>$settings->run($r,$input($op,$outcome),$clock->now()));$role->update(['permissions'=>$permissions]);
    $settings->run($r,$input($op,$outcome),$clock->now());$op->refresh();
    same($attempt,$op->attempt,'Support answer preserves original sealed attempt');
    same($staff->id,$op->policy['enom_answer']['actor_id'],'Answer retains accountable staff identity');
    commerceReject(fn()=>$settings->run($r,$input($op,$outcome),$clock->now()));
    commerceReject(fn()=>$op->update(['policy'=>array_diff_key($op->policy,['enom_answer'=>true])]));$op->refresh();
    if($outcome==='accepted'){
        same('succeeded',$reconcile->run($op->id)->state,'Exact supported order read settles old uncertainty');
        same('staff_answer',$op->fresh()->attempt['reconciliation']['source'],'Completion distinguishes staff evidence from a captured wire reply');
        expect(\Paymenter\Extensions\Servers\Domains\Services\RenewalAuthority::completed($op->fresh(),$op->domain->fresh(),\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->sole())),'Supported completion independently reconstructs its evidence');
        expect(!in_array(\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Orders,$observation->calls,true),'Support order never searches outside the history window');
    }else{
        expect(isset($op->funding['refund']),'Final no-order/no-charge answer settles the original payment');
        same(null,app(\Paymenter\Extensions\Servers\Domains\Services\Refunds::class)->creditRejected($op->id),'Payment returns once');
        expect(\Paymenter\Extensions\Servers\Domains\Services\OperationTerminalProof::safe($op,$op->domain),'Exact supported negative answer is terminal');
    }
    same($before,$writer->calls,'Neither answer resends a purchase');
}
$preflight->cost = '8.95';
// A retained unknown order accepts only a matching support answer, never a negative answer or charge override.
foreach (['', 'wrong-charge'] as $fault) {
    [$op] = $prepare(placeholderComFixtureName('support-captured-'.($fault ?: 'exact').'-placeholder.com'), 'captured');
    $attempt = $op->attempt; $before = $writer->calls;
    commerceReject(fn() => $settings->run($review($op), $input($op, 'accepted'), $clock->now()));
    $clock->instant = $clock->now()->modify('+25 hours');
    commerceReject(fn() => $settings->run($review($op), $input($op, 'not_processed'), $clock->now()));
    $wrong = $input($op, 'accepted'); $wrong['order_reference'] = '9002';
    commerceReject(fn() => $settings->run($review($op), $wrong, $clock->now()));
    $settings->run($review($op), $input($op, 'accepted'), $clock->now());
    same('scheduled', $op->fresh()->policy['status_check']['outcome'], 'Accepted support answer clears stale read status');
    $observation->fault = $fault;
    same($fault === '' ? 'succeeded' : 'uncertain', $reconcile->run($op->id)->state, 'Support never relaxes captured order proof');
    $original = $op->fresh()->attempt; unset($original['reconciliation']);
    same($attempt, $original, 'Support retains the original unknown/sent result');
    same($before, $writer->calls, 'Captured order support never sends Purchase');
}
echo "Double completion-persistence failure recovers only unique original provider charge without replay\n"; $nativeSuccessful = true;
