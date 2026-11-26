<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';

use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{OperationAttempts, StepKey};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, OperationReconciliation, PricingRule, RegistrarAnswers, RenewalFundingLedger, StaffOperationReview, StaffOperations, StaffSettingsReview, StaffSettingsWrites};

[$op, $domain, $binding, $invoice] = $makeRenewal(placeholderComFixtureName('worker-overlap-placeholder.com'));
$file = tempnam((string) getenv('TMPDIR'), 'renewal-workers-');
$source = DB::connection();
$source->statement('VACUUM INTO '.$source->getPdo()->quote($file));
foreach (['renew_first', 'renew_second'] as $name) {
    config(['database.connections.'.$name => [...config('database.connections.sqlite'), 'database' => $file]]);
}
DB::setDefaultConnection('renew_first');
try {
    expect(DB::connection()->getPdo() !== DB::connection('renew_second')->getPdo(), 'Two independent database sessions');
    $op = Operation::findOrFail($op->id);
    [$first, $firstWriter, $completion] = $compose($binding);
    [$second, $secondWriter] = $compose($binding);
    $asSecond = static function (Closure $work) {
        DB::setDefaultConnection('renew_second');
        try { return $work(); }
        finally { DB::setDefaultConnection('renew_first'); }
    };
    $runSecond = static fn () => $asSecond(static fn () => $second->run($op->id, $secondWriter));
    $clock->instant = $clock->now()->modify('-361 seconds');
    // Commit a conflicting revision on the independent connection while the first worker is reading Enom.
    $state->during = static function () use ($op): void {
        DB::connection('renew_second')->transaction(static function () use ($op): void {
            $row = Operation::on('renew_second')->lockForUpdate()->findOrFail($op->id);
            $row->update(['transition_revision' => $row->transition_revision + 1]);
        });
    };
    $conflict = $first->run($op->id, $firstWriter);
    $claim = OperationAttempts::current((int) $op->id, StepKey::renew());
    same(['awaiting_payment', 2, false, 0], [$conflict->state, $conflict->transition_revision, $claim->sealed(), $state->writes],
        'An old admission cannot reserve, dispatch or release a newer revision');
    expect($claim->claiming($clock->now()), 'The new admission owns its full lease');
    $clock->instant = $clock->now()->modify('+361 seconds');
    Cache::flush();
    $duringAdmission = $afterExpiry = null;
    $state->during = static function () use ($runSecond, &$duringAdmission): void {
        $other = $runSecond();
        $duringAdmission = [$other->state, $other->transition_revision, (array) $other->attempt];
    };
    $state->afterWrite = static function () use ($clock, $runSecond, &$afterExpiry): void {
        $clock->instant = $clock->now()->modify('+361 seconds');
        $other = $runSecond();
        $afterExpiry = [$other->state, $other->transition_revision, $other->attempt];
    };
    $money = nativeFinancialSnapshot($op->fresh());
    $reply = $first->run($op->id, $firstWriter);
    same(['awaiting_payment', 3, []], $duringAdmission, 'The second worker preserves the current admission');
    same(['uncertain', 5, array_diff_key($reply->attempt, ['execution' => true, 'renewal' => true])], $afterExpiry, 'Expired reservation is quarantined before the first reply');
    same(['uncertain', 'stale_claim', 'accepted', 6], [$reply->state, $reply->safe_code, renewalReply($reply)['outcome'], $reply->transition_revision],
        'Late acknowledgement observes the original reservation without reviving it');
    same(['item' => [$op->invoice_item_id => PricingRule::minor($op->amount)],
        'payment' => [$invoice->transactions()->sole()->id => PricingRule::minor($op->amount)]],
        RenewalFundingLedger::entries($reply), 'One original invoice line and signed payment are reserved');
    commerceReject(static fn () => $reply->fresh()->update(['attempt' => [...$reply->attempt, 'claim_key' => 'replacement']]));
    commerceReject(static fn () => $reply->fresh()->update(['attempt' => [...$reply->attempt, 'send_state' => 'definitely_unsent']]));
    $sealed = OperationAttempts::sealed((int) $op->id, StepKey::renew());
    same([4, 2, $reply->attempt], [$sealed->operationRevision, $sealed->token->ordinal, $reply->fresh()->attempt],
        'The original reservation and observations remain immutable after a late reply');
    same(['accepted', true], [OperationAttempts::evidence((int) $op->id, $sealed->token->attemptId, 'reply')['outcome'], $sealed->grantConsumedAt !== null],
        'The sealed attempt keeps its one spent grant and late reply');
    $runSecond();
    same(1, $state->writes, 'Both database workers and replay share one registrar send');
    same($money, nativeFinancialSnapshot($op->fresh()), 'Quarantine and replay preserve native customer money');

    // Staff resume a read on their own connection while the original worker is gathering evidence.
    app()->instance(Clock::class, $clock);
    app()->instance(AccountBindings::class, $renewBindings);
    $staff = commerceUser();
    $staff->update(['role_id' => App\Models\Role::create(['name' => 'Review renewal', 'permissions' => [
        'admin.domains.view', 'admin.domains.manage-policy', 'admin.domains.settings.enom_answer', 'admin.domains.manage',
        'admin.invoices.update', 'admin.domains.operations.resume_reads',
    ]])->id]);
    $resume = static function () use ($op, $staff, $clock): void {
        $row = Operation::findOrFail($op->id);
        $ticket = StaffOperationReview::ticket($staff->id, $row->domain_id, $row->id, 'resume_reads',
            StaffOperationReview::stamp($row, $row->domain()->firstOrFail()), $clock);
        (new StaffOperations($clock))->run($staff, $row->domain_id, $row->id, 'resume_reads', $ticket, 'Check the registrar again.');
    };
    $stop = static function () use ($op, $completion, $clock): void {
        for ($i = 0; $i < OperationReconciliation::POLL_LIMIT; $i++) {
            Cache::flush(); $completion->run($op->id); $clock->instant = $clock->now()->modify('+10 minutes');
        }
        expect(RegistrarAnswers::ready($op->fresh(), $clock->now()), 'Stopped reads leave a staff answer available');
    };
    $clock->instant = $clock->now()->modify('+25 hours');
    $state->after = '2027-10-18'; $state->fail = ReadCommand::Order;
    $stop(); $asSecond($resume);
    same(0, $op->fresh()->policy['renewal_polls'], 'The first staff resume clears the stopped poll count');
    $state->after = '2028-10-18'; $state->duringCommand = ReadCommand::Info;
    $state->during = static fn () => $asSecond($resume);
    Cache::flush(); $raced = $completion->run($op->id);
    same([2, 'uncertain', 'advanced_expiry', OperationAttempts::sealed((int) $op->id, StepKey::renew())->token->claimKey],
        [count($raced->policy['renewal_resumptions']), $raced->state, renewalObservation($raced)['observation']['kind'] ?? null,
            renewalObservation($raced)['claim_key'] ?? null], 'The stale read can retain proof but cannot complete the resumed renewal');
    $state->after = '2027-10-18'; $state->duringCommand = null;
    $clock->instant = $clock->now()->modify('+10 minutes'); $stop();
    $ticket = StaffSettingsReview::ticket($staff->id, $op->server_id, null, 'enom_answer', StaffSettingsReview::stamp($op->server_id, null));
    $review = new StaffSettingsReview($staff->id, $op->server_id, null, 'enom_answer', $ticket, 'Record renewal answer');
    $settings = new StaffSettingsWrites($native, $sellers, $renewBindings);
    commerceReject(static fn () => $settings->run($review, ['operation_id' => $op->id, 'attempt_hash' => RegistrarAnswers::identity($op->fresh()),
        'outcome' => 'not_processed', 'order_reference' => '', 'case_reference' => '',
        'evidence' => 'Registrar support checked the exact account and renewal attempt.', 'confirmed' => true], $clock->now()));
    $checked = $op->fresh();
    same(['uncertain', null, null, 1, $money], [$checked->state, $checked->funding['refund_authorization'] ?? null,
        $checked->policy['enom_answer'] ?? null, $state->writes, nativeFinancialSnapshot($checked)],
        'Lagging later reads cannot return the money or authorize a second send');
} finally {
    DB::setDefaultConnection('sqlite');
    DB::purge('renew_first');
    DB::purge('renew_second');
    unlink($file);
}
echo "Concurrent paid renewal workers share one reservation and one send.\n";
$nativeSuccessful = true;
