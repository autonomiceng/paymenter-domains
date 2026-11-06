<?php
// Enom d1392a8 stale/overrun/ambiguous renewal scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
$renewalFixtureNames=['safety-forged','completion-term','completion-wrong-term','completion-order-expiry','completion-charge','completion-owner','completion-id','completion-second-owner','completion-second-id','completion-drift','completion-auto-drift','completion-expiry-race'];
require __DIR__.'/Support/renewal-batch-fixture.php';
use App\Models\{InvoiceTransaction, ServiceCancellation};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DateEvidence, ExpiryPrecision, Money, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
\Paymenter\Extensions\Servers\Domains\Services\ReminderSchema::install();
$invoicePresenter=new \Paymenter\Extensions\Servers\Domains\Services\NativePresenter(new \Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts),$native,new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices));
$admissionReads = [ReadCommand::Info, ReadCommand::RenewalInfo, ReadCommand::Check, ReadCommand::Info, ReadCommand::Renewal, ReadCommand::Balance];
$confirmationReads = [ReadCommand::Info, ReadCommand::Renewal, ReadCommand::Info, ReadCommand::Expiry, ReadCommand::Order];
[$op, $d, $b] = $makeRenewal(placeholderComFixtureName('safety-forged-placeholder.com')); [$engine, $writer] = $compose($b);
$descriptor = new AdmittedOperation($b, new Query($d->domain, Action::Renew, 1), $op->operation_key, 'fake', 1, 'fake', str_repeat('a',64), new Money('8.00','USD'), new DateEvidence('2027-10-18',ExpiryPrecision::Date,'synthetic'));
$ctx = new RequestContext($b, RequestOrigin::Background, $clock->now()->modify('+120 seconds'), new RequestBudget(1), $clock);
commerceReject(fn () => $writer->renew($descriptor, $ctx)); commerceReject(fn () => $writer->renew(clone $descriptor, $ctx));
$state->lost = true; $writes = $state->writes; same('uncertain', $engine->run($op->id, $writer)->state, 'Lost response retains uncertainty');
$nativeBeforeReplay = nativeFinancialSnapshot($op);
$engine->run($op->id, $writer); same($writes + 1, $state->writes, 'Lost response never replays');
same($nativeBeforeReplay, nativeFinancialSnapshot($op), 'Replay preserves native/financial state');
$state->lost = false;
foreach (['term', 'wrong_term', 'order_expiry', 'charge', 'owner', 'id', 'second_owner', 'second_id', 'drift', 'auto_drift', 'expiry_race'] as $case) {
    [$op, $d, $b] = $makeRenewal(placeholderComFixtureName('completion-'.str_replace('_','-',$case).'-placeholder.com')); [$engine, $writer, $completion] = $compose($b);
    same('pending', $engine->run($op->id, $writer)->state, 'Original acknowledged'); $state->expiry = '2028-10-18';
    if ($case === 'term') { $state->after = '2027-10-18'; }
    if ($case === 'charge') { $state->charge = '?'; }
    if ($case === 'owner') { $state->owner = 'foreign'; }
    if ($case === 'id') { $state->id = '999'; }
    if ($case === 'expiry_race') { $state->during = static fn () => $d->update(['confirmed_revision' => 2]); }
    if ($case === 'drift') { $state->charge = '9.00'; }
    if ($case === 'wrong_term') { $state->years = 2; }
    if ($case === 'order_expiry') { $state->orderExpiry = '2028-10-19'; }
    if ($case === 'auto_drift') { $state->auto = '1'; }
    if (in_array($case, ['second_owner', 'second_id'], true)) {
        $state->duringCommand = ReadCommand::Renewal;
        $state->during = static function () use ($state, $case) { if ($case === 'second_owner') { $state->owner = 'foreign'; } else { $state->id = '999'; } };
    }
    $state->calls = [];
    $done = $completion->run($op->id);
    $expectedReads = match ($case) {
        'owner' => [ReadCommand::Info, ReadCommand::Order],
        'second_owner' => [ReadCommand::Info, ReadCommand::Renewal, ReadCommand::Info, ReadCommand::Order],
        default => $confirmationReads,
    };
    same($expectedReads, $state->calls, 'Reconciliation refusal retains exact identity/expiry/order sequence '.$case);
    $state->duringCommand = null; $state->orderExpiry = null;
    same(in_array($case, ['drift', 'auto_drift'], true) ? 'succeeded' : 'pending', $done->state, 'Completion '.$case.' proof policy');
    if ($case === 'drift') { same(true, $d->fresh()->renewal_policy['automatic_spend_paused'], 'Incurred actual overrun pauses future spending'); same('9.00', $done->cost['actual']['amount'], 'Actual charge retained'); }
    elseif ($case === 'auto_drift') { same(['auto_renew'], $done->attempt['reconciliation']['observed_corrections'], 'Enom auto-renew drift is correction evidence, not a completion veto'); }
    else { same('2027-10-18', $d->fresh()->provider_expires_at->format('Y-m-d'), 'Ambiguous proof never stamps expiry'); }
}
same([],array_keys($registrations),'Every independent renewal scenario ran');
echo "Renewal refusal, one-attempt grants, fresh rechecks and completion safety passed\n"; $nativeSuccessful = true;
