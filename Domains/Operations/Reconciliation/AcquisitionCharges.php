<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Reconciliation;

use Paymenter\Extensions\Servers\Domains\Data\{AttemptEvidence, Evidence, OrderEvidence};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\AcquisitionChargeMatch;
use Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence;

/** The charge side of a read. Ownership and expiry are verified separately for completion. */
final readonly class AcquisitionCharges
{
    private function __construct(public AcquisitionChargeMatch $match, public array $orders) {}

    public static function from(AttemptEvidence $attempt, Evidence $facts, \DateTimeImmutable $now): ?self
    {
        $history = $attempt->historyAcceptance?->covers($attempt, $now) ?? false;
        $captured = $attempt->executionFacts !== null && $attempt->providerReference !== null;
        if (!$history && !$captured) { return null; }
        try { $attempt->executionFacts?->requireAttempt($attempt); }
        catch (\InvalidArgumentException) { return null; }

        $orders = [];
        foreach ($facts->candidates as $order) {
            if (!self::matches($attempt, $order)) { continue; }
            $orders[] = ['reference'=>$order->providerReference, 'amount'=>$order->charge->amount,
                'currency'=>$order->charge->currency, 'processed_at'=>$order->processedAt->value];
        }
        if ($orders === []) { return null; }
        $unique = count($orders) === 1 && count($facts->candidates) === 1 && ($captured || $facts->historyComplete);
        return new self($unique ? AcquisitionChargeMatch::Exact : AcquisitionChargeMatch::Ambiguous, $orders);
    }

    private static function matches(AttemptEvidence $attempt, OrderEvidence $order): bool
    {
        return $order->query->matches($attempt->query) && $order->binding->matches($attempt->binding)
            && ($attempt->providerReference === null || $order->providerReference === $attempt->providerReference)
            && $order->charge?->currency === 'USD' && $order->processedAt !== null
            && ReconciliationEvidence::processedWithin($attempt, $order->processedAt, $order->providerReference);
    }
}
