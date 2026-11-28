<?php
// Enom 444f31c reconciliation-registration/provider-disposition; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Reconciliation;

use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionChargeMatch, AcquisitionReadPermit, OperationWriter, Transition};
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryInvoices, HoldingObservationWork, InvoiceFunding,
    ObservationProjection, RegistrationDns, RenewalStop, WriteMoney};

/** Native paid completion and holding assignment commit with the original read settlement. */
final class AcquisitionSettlement
{
    public static function complete(Operation $op, Domain $domain, InvoiceFunding $funding, AcquisitionRead $read,
        AcquisitionObservation $observed, array $policy, OperationWriter $writer, AcquisitionReadPermit $permit): void
    {
        $now = $permit->now;
        $proof = $observed->proof ?? throw new \LogicException('Settlement needs completion proof.');
        $nameservers = $observed->nameservers;
        $funding->completeInitial($op, $domain); // Persistence failures roll back the whole native/domain decision.
        $cost = $proof['charge']; $expected = $read->attempt->expectedCharge;
        $difference = $expected && ($expected->currency !== 'USD' || rtrim($expected->amount, '0') !== rtrim($cost['amount'], '0'));
        $corrections = ($op->attempt['corrections'] ?? $op->attempt['evidence']['registration']['corrections'] ?? []) !== [] || $proof['observed_corrections'] !== [];
        $below = $op->funding_basis!=='complimentary' && WriteMoney::compare($cost['amount'], $op->amount) > 0;
        $nameserversHeld=RenewalStop::blocksExisting($funding->initialFacts($domain))||($domain->renewal_policy['automatic_spend_paused']??false);
        $staffCorrections=$op->action==='register'?array_diff(RegistrationDns::corrections($op,$proof['observed_corrections']),$nameserversHeld?[]:['nameservers'])!==[]:$corrections;
        $policy['reconciliation']['observed_corrections'] = $proof['observed_corrections']; $policy['reconciliation']['staff_alert'] = $difference || $staffCorrections || $below;
        $policy['reconciliation']['reason'] = $below ? 'below_cost' : ($difference ? 'actual_charge_changed' : ($corrections ? 'correction_required' : 'confirmed'));
        $op->update(['state' => 'succeeded', 'safe_code' => $corrections ? 'registration_correction_required' : ($difference ? 'actual_charge_changed' : null),
            'cost' => [...($op->cost ?? []), 'actual' => $cost],
            'policy' => $policy, 'provider_order_id' => $proof['order_reference'], 'confirmed_expires_at' => $proof['expiry'],
            'completed_at' => $now, 'lease_expires_at' => null]);
        if ($writer->completeAcquisition($permit, ['reconciliation'=>$proof, 'charge_match'=>AcquisitionChargeMatch::Exact->value])
            !== Transition::Applied) {
            throw new \DomainException('Acquisition settlement authority changed.');
        }
        $domain->update(['status' => 'active', 'provider_expires_at' => $proof['expiry'], 'provider_order_id' => $proof['order_reference'],
            'provider_domain_id' => $proof['provider_domain_id'], 'confirmed_revision' => $domain->confirmed_revision + 1,
            'renewal_policy' => [...($domain->renewal_policy ?? []), 'billing_anchor' => ['expiry' => $proof['expiry'], 'operation_key' => $op->operation_key]],
            'observation' => ObservationProjection::acquired($domain, $op->operation_key, $nameservers), 'last_synced_at' => $now]);
        HoldingObservationWork::queueInitial($domain,$now);
        ComplimentaryInvoices::complete($op);
    }
}
