<?php
// Enom d1392a8 stale/overrun/ambiguous renewal scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
$renewalFixtureNames=['renew-eighteen','safety-unpaid','safety-reversed','safety-invoice-due','safety-invoice-due-null','safety-cancelled','safety-paused','safety-cost','safety-unknown','safety-disabled','safety-authority','safety-boundary','safety-rate','safety-second-flags','safety-second-cost','safety-balance','safety-balance-unknown','safety-second-balance','safety-second-balance-unknown','safety-quote-deadline','safety-seal-reversal'];
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
[$op, $d, $b] = $makeRenewal(placeholderComFixtureName('renew-eighteen-placeholder.com')); [$engine, $writer, $completion] = $compose($b); $writes = $state->writes;
same('pending', $engine->run($op->id, $writer)->state, 'Ordinary renewal acknowledges once');
same([...$admissionReads, ...$admissionReads], $state->calls, 'Two independent six-read admissions preserve exact order');
same($writes + 1, $state->writes, 'Exactly one Extend between admission and confirmation');
$state->expiry = '2028-10-18'; $state->charge = '8.00';
same('succeeded', $completion->run($op->id)->state, 'Ordinary renewal requires actual charge and full-year expiry');
same([...$admissionReads, ...$admissionReads, ...$confirmationReads], $state->calls, 'Captured renewal confirmation retains both fresh identities, dedicated expiry and order');
same(18, count($state->calls) + $state->writes - $writes, 'Ordinary success uses 12 admission + 1 Extend + 5 confirmation calls');
$completion->run($op->id); $engine->run($op->id, $writer);
same(18, count($state->calls) + $state->writes - $writes, 'Completed workers remain silent');
foreach (['unpaid', 'reversed', 'invoice_due', 'invoice_due_null', 'cancelled', 'paused', 'cost', 'unknown', 'disabled', 'authority', 'boundary', 'rate', 'second_flags', 'second_cost', 'balance', 'balance_unknown', 'second_balance', 'second_balance_unknown', 'quote_deadline', 'seal_reversal'] as $case) {
    [$op, $d, $b, $invoice] = $makeRenewal(placeholderComFixtureName('safety-'.str_replace('_','-',$case).'-placeholder.com'), $case !== 'unpaid');
    [$engine, $writer] = $compose($b, $case !== 'disabled'); $writes = $state->writes;
    if ($case === 'reversed') { InvoiceTransaction::create(['invoice_id' => $invoice->id, 'amount' => '-0.01', 'fee' => '0.00', 'status' => 'succeeded']); }
    // Retained native rows are needed to check the execution hold, not just a validator exception.
    if (in_array($case,['invoice_due','invoice_due_null'],true)) { DB::table('invoices')->where('id', $invoice->id)->update(['due_at' => $case==='invoice_due'? '2027-10-19':null]); }
    if ($case === 'cancelled') { ServiceCancellation::create(['service_id' => $d->service_id, 'type' => 'immediate', 'reason' => 'Synthetic']); }
    if ($case === 'paused') { $d->refresh()->update(['renewal_policy' => [...$d->renewal_policy, 'automatic_spend_paused' => true]]); }
    if ($case === 'cost') { $state->price = '8.01'; }
    if ($case === 'unknown') { $state->price = '?'; }
    if (in_array($case, ['balance', 'balance_unknown'], true)) { $state->balance = $case === 'balance' ? '7.99' : '?'; }
    if ($case === 'quote_deadline') { $state->duringCommand = ReadCommand::Renewal; $state->during = static fn () => $clock->instant = $clock->now()->modify('+121 seconds'); }
    if ($case === 'seal_reversal') {
        $state->duringCommand = ReadCommand::Balance;
        $state->during = static function () use ($state, $invoice) {
            $state->during = static fn () => InvoiceTransaction::create(['invoice_id' => $invoice->id, 'amount' => '-0.01', 'fee' => '0.00', 'status' => 'succeeded']);
        };
    }
    if ($case === 'authority') { $state->during = static fn () => $d->update(['confirmed_revision' => 2]); }
    if ($case === 'boundary') { $state->duringCommand = ReadCommand::Renewal; $state->during = static fn () => $clock->instant = new DateTimeImmutable('2027-10-18T12:00:01Z'); }
    if ($case === 'rate') { $accepted = new Paymenter\Extensions\Servers\Domains\Services\RenewalAcceptance($b, true, true, 25, true); $accepted->reserve($b); }
    if (str_starts_with($case, 'second_')) {
        $state->duringCommand = ReadCommand::Balance;
        $state->during = static function () use ($state, $case) { $state->duringCommand = ReadCommand::RenewalInfo; $state->during = static function () use ($state, $case) { if ($case === 'second_flags') { $state->auto = '1'; } elseif ($case === 'second_cost') { $state->price = '9.00'; } else { $state->balance = $case === 'second_balance' ? '7.99' : '?'; } }; };
    }
    same($case === 'unpaid' ? 'awaiting_payment' : (in_array($case,['rate','boundary','disabled','balance','balance_unknown','second_balance','second_balance_unknown','quote_deadline','seal_reversal'],true)?'queued':'held'), $engine->run($op->id, $writer)->state, 'Unsafe '.$case.' retained');
    same($writes, $state->writes, 'Unsafe '.$case.' sends nothing'); $held = $op->fresh();
    if ($cause = ['reversed' => 'renewal_invoice_unpaid', 'invoice_due' => 'renewal_funding_or_authority', 'invoice_due_null' => 'renewal_funding_or_authority', 'paused' => 'renewal_spend_paused', 'cancelled' => 'renewal_funding_or_authority'][$case] ?? null) { same($cause, $held->safe_code, 'Renewal hold keeps its cause: '.$case); }
    same([], $held->attempt ?? [], 'Refusal precedes seal'); if ($case !== 'unpaid') { same(!in_array($case,['rate','boundary','disabled','balance','balance_unknown','second_balance','second_balance_unknown','quote_deadline','seal_reversal'],true), $held->policy['renewal_execution']['refundable_later'], 'Paid debt retained for26'); }
    else { App\Helpers\ExtensionHelper::addPayment($invoice, null, $op->amount); same('pending', $engine->run($op->id,$writer)->state, 'Late native settlement executes after an early unpaid check'); }
    if ($case === 'disabled') { same('pending', $runRenewal($held,$b)->state, 'Enabling execution recovers the same paid obligation'); }
    $expectedReads = match ($case) {
        'cost', 'second_cost', 'second_balance', 'second_balance_unknown', 'seal_reversal' => [...$admissionReads, ...$admissionReads],
        'second_flags' => [...$admissionReads, ...array_slice($admissionReads, 0, 5)],
        'balance', 'balance_unknown', 'boundary', 'quote_deadline' => $admissionReads,
        'unknown' => array_slice($admissionReads, 0, 3),
        'authority' => $admissionReads,
        default => null,
    };
    if ($expectedReads !== null) { same($expectedReads, $state->calls, 'Exact fresh refusal sequence '.$case); }
    if(in_array($case,['balance','balance_unknown','second_balance','second_balance_unknown'],true)){
        same(1,DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','renewal_funds')->whereNull('resolved_at')->count(),'Registrar funds shortage immediately has a durable renewal alert');
    }
    $state->balance = '100.00';
    same('2027-10-18', $d->fresh()->provider_expires_at->format('Y-m-d'), 'No fabricated expiry');
    if (in_array($case, ['cost', 'second_cost'], true)) { same(true, $d->fresh()->renewal_policy['automatic_spend_paused'], 'Overrun pauses spending'); }
    $state->duringCommand = null;
}
same([],array_keys($registrations),'Every independent renewal scenario ran');
echo "Renewal admission safety passed\n"; $nativeSuccessful=true;
