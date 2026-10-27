<?php
// Enom 444f31c original-attempt reconciliation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AttemptEvidence, DispatchSeal, DateEvidence, Disposition, Evidence, ExecutionRecord, ExpiryPrecision, Money, PersistedExecution, SendState};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
final class ReconciliationEvidence
{
    public static function attempt(Operation $op, Domain $domain, AccountBinding $binding): AttemptEvidence
    {
        $a = $op->attempt ?? []; $query = OperationClaims::query($op, $domain);
        if (!$op->started_at || !$op->request_fingerprint || !isset($a['claim_key'], $a['dispatch_attempted_at'])
            || ($a['seal'] ?? null) !== 'sealed') { throw new \DomainException('No sealed original attempt.'); }
        $end = $op->started_at->modify('+'.ExecuteOperation::EXECUTION_SECONDS.' seconds');
        $dispatch = new \DateTimeImmutable($a['dispatch_attempted_at']);
        if ($dispatch < $op->started_at || $dispatch > $end) { throw new \DomainException('Invalid original window.'); }
        $record = !array_key_exists('execution', $a) ? ExecutionRecord::Absent : ($a['execution'] === null ? ExecutionRecord::RecordedNull : ExecutionRecord::Recorded);
        $registration = $op->action === 'renew' ? ($a['renewal'] ?? null) : ($a['registration'] ?? null);
        $raw = $op->action === 'renew' ? $registration : ($a['evidence']['registration'] ?? null);
        $ref = $registration['order_reference'] ?? $raw['orderReference'] ?? null;
        foreach ([$registration, $raw] as $facts) {
            if ($facts !== null && (($facts['query']['name'] ?? null) !== $query->name || ($facts['query']['years'] ?? null) !== $query->years
                || ($facts['order_reference'] ?? $facts['orderReference'] ?? null) !== $ref)) { throw new \DomainException('Contradictory retained acceptance.'); }
        }
        if ($op->action === 'transfer') { $registration = $raw = null; $ref = null; }
        if ($op->action !== 'transfer' && $op->provider_order_id !== null && $op->provider_order_id !== $ref) { throw new \DomainException('Order reference changed.'); }
        $execution = null;
        if ($record === ExecutionRecord::Recorded && $raw !== null) {
            $disposition = Disposition::from($a['execution']['disposition']); $send = SendState::from($a['execution']['send_state']);
            if (in_array($disposition, [Disposition::Accepted, Disposition::AcceptedWithDiscrepancy], true) && $send === SendState::Sent) {
                $execution = new PersistedExecution($binding, $query, $op->operation_key, $disposition, $send, $ref, $op->action === 'renew' ? ($a['renewal_mode'] ?? null) : null);
            }
        }
        $cost = $a['authorized_cost'] ?? null;
        return new AttemptEvidence($binding, $query, $op->operation_key, $op->authority_generation, $record, $execution ? SendState::Sent : SendState::PossiblySent,
            $dispatch, $end, expectedCharge: $cost ? new Money($cost['amount'], $cost['currency']) : null,
            seal: DispatchSeal::Sealed, providerReference: $ref, expectedExpiry: $op->expected_expires_at ? new DateEvidence($op->expected_expires_at->format('Y-m-d'), ExpiryPrecision::Date, 'original_expected') : null,
            beforeExpiry: in_array($op->action, ['renew', 'transfer'], true) && ($op->base_expires_at || ($a['transfer_admission']['base_expiry'] ?? null)) ? new DateEvidence($op->action === 'transfer' ? $a['transfer_admission']['base_expiry'] : $op->base_expires_at->format('Y-m-d'), ExpiryPrecision::Date, 'acquisition_base') : null, executionFacts: $execution, historyAcceptance: $execution && $ref !== null ? null : HistoryAcceptances::load($binding, $query->action), transferReference: $op->action === 'transfer' ? ($a['evidence']['transferReference'] ?? null) : null,
            expectedPrivacy: $op->action === 'transfer' && is_bool($a['transfer_admission']['privacy'] ?? null) ? $a['transfer_admission']['privacy'] : null);
    }
    /** Exactly one charged original order AND owned full-term expiry; neither read alone is completion. */
    public static function completion(AttemptEvidence $attempt, Evidence $facts, \DateTimeImmutable $now): ?array
    {
        if ($attempt->query->action === \Paymenter\Extensions\Servers\Domains\Data\Action::Transfer
            && ($facts->transfer !== \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Completed || !$attempt->beforeExpiry
                || ($attempt->binding->registrarKey === 'enom' && (!$attempt->transferReference || $facts->transferReference !== $attempt->transferReference)))) { return null; }
        if ($attempt->query->action === \Paymenter\Extensions\Servers\Domains\Data\Action::Transfer && $attempt->binding->registrarKey === 'namesilo'
            && ($facts->domain?->active !== true || $facts->domain->providerAutoRenew !== false
                || $attempt->expectedPrivacy === null || $facts->domain->privacy !== $attempt->expectedPrivacy)) { return null; }
        if (count($facts->candidates) !== 1) { return null; }
        try { $attempt->executionFacts?->requireAttempt($attempt); } catch (\InvalidArgumentException) { return null; }
        $history = $facts->historyComplete && ($attempt->historyAcceptance?->covers($attempt, $now) ?? false);
        $order = $facts->candidates[0]; $domain = $facts->domain; $date = $order->processedAt;
        if (!$order->query->matches($attempt->query) || !$order->binding->matches($attempt->binding)
            || !$domain || !$domain->binding->matches($attempt->binding) || $domain->name !== $attempt->query->name
            || ($attempt->providerReference !== null && $order->providerReference !== $attempt->providerReference)
            || !$order->charge || $order->charge->currency !== 'USD' || !$date || !self::processedWithin($attempt, $date, $order->providerReference)
            || (!$attempt->executionFacts && !$history)
            || ($attempt->providerReference === null && !$history)
            || !$domain || $domain->owned !== true || !$domain->expiry) { return null; }
        $expiry = substr($domain->expiry->value, 0, 10);
        $base = $attempt->beforeExpiry ? new \DateTimeImmutable($attempt->beforeExpiry->value) : $attempt->windowStart;
        $earliest = $base->modify('+'.$attempt->query->years.' years')->modify('-2 days')->format('Y-m-d');
        $latest = ($attempt->beforeExpiry ? $base : $attempt->windowEnd)->modify('+'.$attempt->query->years.' years')->modify('+2 days')->format('Y-m-d');
        if ($attempt->query->action === \Paymenter\Extensions\Servers\Domains\Data\Action::Transfer) {
            $expected = \Carbon\CarbonImmutable::instance($base)->addYearsNoOverflow($attempt->query->years)->format('Y-m-d');
            if ($expiry !== $expected) { return null; }
        }
        // Provider dates may differ by a timezone boundary, but never by a missing year/term.
        if ($expiry < $earliest || $expiry > $latest || ($attempt->expectedExpiry && substr($attempt->expectedExpiry->value, 0, 10) !== $expiry)) { return null; }
        if ($attempt->binding->registrarKey === 'enom') {
            if (!$order->afterExpiry || substr($order->afterExpiry->value, 0, 10) !== $expiry) { return null; }
            if ($order->afterExpiry->precision === ExpiryPrecision::Instant && $domain->expiry->precision === ExpiryPrecision::Instant
                && new \DateTimeImmutable($order->afterExpiry->value) != new \DateTimeImmutable($domain->expiry->value)) { return null; }
        }
        return ['transfer_status' => $facts->transfer->value, 'base_expiry' => $attempt->beforeExpiry?->value, 'order_reference' => $order->providerReference, 'charge' => ['amount' => $order->charge->amount, 'currency' => 'USD'],
            'processed_at' => $date->value, 'processed_precision' => $date->precision->value,
            'processed_source' => $date->source, 'processed_timezone' => $date->timezone, 'expiry' => $expiry, 'provider_domain_id' => $domain->providerReference,
            'operation_key' => $attempt->operationKey, 'generation' => $attempt->authorityGeneration,
            'fingerprint' => $attempt->binding->fingerprint, 'query' => ['name' => $attempt->query->name, 'action' => $attempt->query->action->value, 'years' => $attempt->query->years],
            'source' => $attempt->executionFacts && $attempt->providerReference !== null ? 'captured_order' : 'accepted_history',
            'expiry_precision' => $domain->expiry->precision->value, 'observed_corrections' => $domain->providerAutoRenew === false ? [] : ['auto_renew'], 'window_start' => $attempt->windowStart->format(DATE_ATOM),
            'window_end' => $attempt->windowEnd->format(DATE_ATOM)];
    }
    /** Local = UTC + offset: some offset in [-12h, +14h] must fit the sealed window. */
    public static function processedWithin(AttemptEvidence $attempt, DateEvidence $date, ?string $reference): bool
    {
        $zone = new \DateTimeZone('UTC');
        $start = $attempt->windowStart->setTimezone($zone); $end = $attempt->windowEnd->setTimezone($zone);
        if ($date->precision === ExpiryPrecision::Instant) {
            $instant = new \DateTimeImmutable($date->value);
            return $instant >= $start && $instant <= $end;
        }
        if ($date->precision !== ExpiryPrecision::Local || $date->source !== 'enom_order_bill_date'
            || $attempt->binding->registrarKey !== 'enom' || !$attempt->executionFacts
            || $attempt->providerReference === null || $reference !== $attempt->providerReference) { return false; }
        try { $attempt->executionFacts->requireAttempt($attempt); } catch (\InvalidArgumentException) { return false; }
        // A coordinate for comparing wall clocks, not an assertion that the observation was UTC.
        $local = new \DateTimeImmutable($date->value, $zone);
        return $local >= $start->modify('-12 hours') && $local <= $end->modify('+14 hours');
    }
}
