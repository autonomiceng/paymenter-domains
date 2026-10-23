<?php
// Enom d1392a8 stale/overrun/ambiguous renewal scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{InvoiceTransaction, ServiceCancellation};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DateEvidence, ExpiryPrecision, Money, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
foreach (['unpaid', 'reversed', 'cancelled', 'cost', 'unknown', 'disabled', 'authority', 'boundary', 'rate', 'second_flags', 'second_cost'] as $case) {
    [$op, $d, $b, $invoice] = $makeRenewal('safety-'.str_replace('_','-',$case).'.com', $case !== 'unpaid');
    [$engine, $writer] = $compose($b, $case !== 'disabled'); $writes = $state->writes;
    if ($case === 'reversed') { InvoiceTransaction::create(['invoice_id' => $invoice->id, 'amount' => '-0.01', 'fee' => '0.00', 'status' => 'succeeded']); }
    if ($case === 'cancelled') { ServiceCancellation::create(['service_id' => $d->service_id, 'type' => 'immediate', 'reason' => 'Synthetic']); }
    if ($case === 'cost') { $state->price = '8.01'; }
    if ($case === 'unknown') { $state->price = '?'; }
    if ($case === 'authority') { $state->during = static fn () => $d->update(['confirmed_revision' => 2]); }
    if ($case === 'boundary') { $state->duringCommand = ReadCommand::Renewal; $state->during = static fn () => $clock->instant = new DateTimeImmutable('2027-10-18T12:00:01Z'); }
    if ($case === 'rate') { $accepted = new Paymenter\Extensions\Servers\Domains\Services\RenewalAcceptance($b, true, true, 25, true); $accepted->reserve($b); }
    if (str_starts_with($case, 'second_')) {
        $state->duringCommand = ReadCommand::Balance;
        $state->during = static function () use ($state, $case) { $state->duringCommand = ReadCommand::RenewalInfo; $state->during = static function () use ($state, $case) { if ($case === 'second_flags') { $state->auto = '1'; } else { $state->price = '9.00'; } }; };
    }
    same($case === 'unpaid' ? 'awaiting_payment' : (in_array($case,['rate','boundary','disabled'],true)?'queued':'held'), $engine->run($op->id, $writer)->state, 'Unsafe '.$case.' retained');
    same($writes, $state->writes, 'Unsafe '.$case.' sends nothing'); $held = $op->fresh();
    same([], $held->attempt ?? [], 'Refusal precedes seal'); if ($case !== 'unpaid') { same(!in_array($case,['rate','boundary','disabled'],true), $held->policy['renewal_execution']['refundable_later'], 'Paid debt retained for26'); }
    else { App\Helpers\ExtensionHelper::addPayment($invoice, null, $op->amount); same('pending', $engine->run($op->id,$writer)->state, 'Late native settlement executes after an early unpaid check'); }
    if ($case === 'disabled') { same('pending', $runRenewal($held,$b)->state, 'Enabling execution recovers the same paid obligation'); }
    same('2027-10-18', $d->fresh()->provider_expires_at->format('Y-m-d'), 'No fabricated expiry');
    if (in_array($case, ['cost', 'second_cost'], true)) { same(true, $d->fresh()->renewal_policy['automatic_spend_paused'], 'Overrun pauses spending'); }
    $state->duringCommand = null;
}
[$op, $d, $b] = $makeRenewal('safety-forged.com'); [$engine, $writer] = $compose($b);
$descriptor = new AdmittedOperation($b, new Query($d->domain, Action::Renew, 1), $op->operation_key, 'fake', 1, 'fake', str_repeat('a',64), new Money('8.00','USD'), new DateEvidence('2027-10-18',ExpiryPrecision::Date,'synthetic'));
$ctx = new RequestContext($b, RequestOrigin::Background, $clock->now()->modify('+120 seconds'), new RequestBudget(1), $clock);
commerceReject(fn () => $writer->renew($descriptor, $ctx)); commerceReject(fn () => $writer->renew(clone $descriptor, $ctx));
$state->lost = true; $writes = $state->writes; same('uncertain', $engine->run($op->id, $writer)->state, 'Lost response retains uncertainty');
$engine->run($op->id, $writer); same($writes + 1, $state->writes, 'Lost response never replays');
$state->lost = false;
foreach (['term', 'charge', 'owner', 'id', 'drift', 'expiry_race'] as $case) {
    [$op, $d, $b] = $makeRenewal('completion-'.str_replace('_','-',$case).'.com'); [$engine, $writer, $completion] = $compose($b);
    same('pending', $engine->run($op->id, $writer)->state, 'Original acknowledged'); $state->expiry = '2028-10-18';
    if ($case === 'term') { $state->after = '2027-10-18'; }
    if ($case === 'charge') { $state->charge = '?'; }
    if ($case === 'owner') { $state->owner = 'foreign'; }
    if ($case === 'id') { $state->id = '999'; }
    if ($case === 'expiry_race') { $state->during = static fn () => $d->update(['confirmed_revision' => 2]); }
    if ($case === 'drift') { $state->charge = '9.00'; }
    $done = $completion->run($op->id);
    same($case === 'drift' ? 'succeeded' : 'pending', $done->state, 'Completion '.$case.' proof policy');
    if ($case === 'drift') { same(true, $d->fresh()->renewal_policy['automatic_spend_paused'], 'Incurred actual overrun pauses future spending'); same('9.00', $done->cost['actual']['amount'], 'Actual charge retained'); }
    else { same('2027-10-18', $d->fresh()->provider_expires_at->format('Y-m-d'), 'Ambiguous proof never stamps expiry'); }
}
echo "Renewal refusal, one-attempt grants, fresh rechecks and completion safety passed\n"; $nativeSuccessful = true;
