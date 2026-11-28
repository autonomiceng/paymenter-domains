<?php
// Synthetic admitted bridges; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/write-fixture.php';
use App\Models\Credit;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, Money, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\AcquisitionPhase;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, RegistrarFactories, RegistrarRegistry, StaffOperationReview, WriteAcceptance, WriteFeatures};
$off = new WriteFeatures;
foreach ([Action::Register, Action::Transfer, Action::Renew] as $action) { commerceReject(fn () => $off->requireEnabled($action)); }
commerceReject(fn () => $off->requireManagement());
$bridge = $build($enomBinding, $off); $op = $new(placeholderComFixtureName('write-disabled-placeholder.com'));
$result = $engine->execute($op->id); same('held', $result->state, 'Disabled writes hold'); same('write_disabled', $result->safe_code, 'Neutral reason');
same(null, $result->attempt, 'Disabled write creates no attempt'); same(0, $preflight->calls, 'Disabled gate blocks read transport too'); same([], $transport->calls, 'No write transport');
$stale = $new(placeholderComFixtureName('disabled-stale-placeholder.com')); $claims->claim($stale->id);
$clock->instant = $clock->now()->modify('+361 seconds'); $stale = $engine->execute($stale->id);
same(['held', 'write_disabled', 'absent', null], [$stale->state, $stale->safe_code, $stale->attempt['seal'], $stale->lease_expires_at],
    'Disabled writes leave the expired unsealed worker in an admission hold');
same([], $transport->calls, 'The admission hold sends nothing');
same(AcquisitionPhase::ProvedUnsent,$stale->acquisitionLifecycle($clock->now())?->phase,'The retired worker proves the purchase never left');
require_once __DIR__.'/Support/staff-panel.php';
staffPanel();
$staff=staffUser(['admin.domains.view','admin.domains.manage','admin.invoices.update',StaffOperationReview::permission('refund')]);
$credit=static fn (): int => PricingRule::minor((string) (Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount')??'0.00'));
$expected=$credit()+PricingRule::minor($stale->amount);
$reason=['reason'=>'Cancel the expired unsent registration'];
$replay=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$stale->id])->mountAction('refund');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$stale->id])->assertActionVisible('refund')
    ->callAction('refund',$reason)->assertHasNoActionErrors();
$closed=$stale->fresh();
same(['rejected','refund_approved','cancelled',$expected],[$closed->state,$closed->safe_code,$closed->domain->service->status,$credit()],
    'The refund click credits the paid registration with an expired unsealed worker');
$replay->setActionData($reason)->callMountedAction();
same([$expected,[]],[$credit(),$transport->calls],'Replaying the refund leaves one exact credit and no registrar send');
Illuminate\Support\Facades\Auth::setUser($user);
$clock->instant = $clock->now()->modify('-361 seconds');
$bridge = $build($enomBinding); $op = $new(placeholderComFixtureName('bridge-enom-placeholder.com'));
$result = $engine->execute($op->id); same(1, count($transport->calls), 'Enom bridge called once');
same('uncertain', $result->state, 'Bridge does not interpret outcome'); $call = $transport->calls[0];
same('https://resellertest.enom.com/interface.asp', $call['origin'], 'Fixed test origin'); same(25000, $call['timeoutMs'], 'Full bounded deadline');
same($op->payload, $call['payload'], 'Only locked frozen payload reaches wire port');
same('99.00', $call['op']->authorizedCost->amount, 'No dispatch cost floor');
commerceReject(fn () => $bridge->register($call['op'], $call['context']));
commerceReject(fn () => $bridge->register(clone $call['op'], $call['context']));
commerceReject(fn () => $bridge->register($call['op'], new RequestContext($enomBinding, RequestOrigin::Background, $call['context']->deadline, new RequestBudget(1), $clock)));
$forged = new AdmittedOperation($enomBinding, new Query('forged-placeholder.com', Action::Register, 1), 'forged', 'claim', 1, 'funding', str_repeat('a', 64), new Money('1.00', 'USD'));
commerceReject(fn () => $bridge->register($forged, $call['context']));
// Holding the transport itself is insufficient: its final entry consumes a live engine grant.
$accepted = new WriteAcceptance($enomBinding, true, true, 25,catalogue: $GLOBALS['caps']??null);
commerceReject(fn () => $transport->register($forged, $call['context'], $engine, new WriteFeatures(registration: true), $accepted));
commerceReject(fn () => $transport->register($call['op'], $call['context'], $engine, new WriteFeatures(registration: true), $accepted));
try { $transport->sendRegistration($forged, $call['context'], [], 'https://invalid.example', 1); expect(false, 'Wire method must be inaccessible'); } catch (Error) {}
commerceReject(fn () => $bridge->transferIn($call['op'], $call['context'])); commerceReject(fn () => $bridge->renew($call['op'], $call['context']));
same(1, count($transport->calls), 'Descriptors, clones, contexts and other methods confer no authority');
$transport->fail = true; $build($enomBinding); $op = $new(placeholderComFixtureName('bridge-lost-placeholder.com')); $result = $engine->execute($op->id); $transport->fail = false;
same(null, $result->attempt['execution'], 'Thrown transport retains recorded-null uncertainty'); same('uncertain', $result->state, 'No retry on transport exception');
$nativeBeforeReplay = nativeFinancialSnapshot($op);
$engine->execute($op->id); same(2, count($transport->calls), 'No replay');
same($nativeBeforeReplay, nativeFinancialSnapshot($op), 'Replay preserves native/financial state');
echo "Admitted dual-driver write bridge checks passed\n"; $nativeSuccessful = true;
