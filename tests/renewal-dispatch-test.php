<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, DateEvidence, Disposition, DomainSnapshot, Environment, ExpiryPrecision, Money, OrderEvidence, Query, SendState};
use Paymenter\Extensions\Servers\Domains\Services\RenewalHold;
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalDecision;

$now = new DateTimeImmutable('2027-10-01T12:00:00Z');
$future = $now->modify('+1 second');
foreach ([
    ['awaiting_payment', null, null, 'admit'],
    ['queued', null, $now, 'admit'],
    ['queued', null, $future, null],
    ['running', $future, null, null],
    ['running', $now, null, 'quarantine'],
    ['running', null, null, 'quarantine'],
    ['pending', null, null, null],
    ['uncertain', null, null, null],
    ['held', null, null, null],
    ['succeeded', null, null, null],
] as [$state, $lease, $admissionUntil, $decision]) {
    same($decision, RenewalDecision::admission($state, $lease, $admissionUntil, $now), $state.' admission');
}

foreach ([
    ['current reservation', 4, 'running', true, true, '+1 second', '+25 seconds', true],
    ['superseded revision', 5, 'running', true, true, '+1 second', '+25 seconds', false],
    ['quarantined after crash', 4, 'uncertain', true, true, '+1 second', '+25 seconds', false],
    ['different claim or holding', 4, 'running', false, true, '+1 second', '+25 seconds', false],
    ['different funding postings', 4, 'running', true, false, '+1 second', '+25 seconds', false],
    ['missing lease', 4, 'running', true, true, null, '+25 seconds', false],
    ['expired lease', 4, 'running', true, true, '+0 seconds', '+25 seconds', false],
    ['insufficient wire time', 4, 'running', true, true, '+1 second', '+24999 milliseconds', false],
] as [$case, $revision, $state, $matches, $funded, $lease, $deadline, $expected]) {
    same($expected, RenewalDecision::canSend(4, $revision, $state, $matches, $funded,
        $lease === null ? null : $now->modify($lease), $now->modify($deadline), $now), $case.' wire authority');
}

foreach ([
    ['acknowledged', 'running', SendState::Sent, Disposition::Accepted, true, 'pending', 'renewal_confirmation_required'],
    ['pending', 'running', SendState::Sent, Disposition::Pending, true, 'pending', 'renewal_confirmation_required'],
    ['local refusal', 'running', SendState::DefinitelyUnsent, Disposition::Rejected, true, 'held', 'renewal_definitely_unsent'],
    ['lost reply', 'running', null, null, true, 'uncertain', 'renewal_confirmation_required'],
    ['unknown result', 'running', SendState::PossiblySent, Disposition::Unknown, true, 'uncertain', 'renewal_confirmation_required'],
    ['late reply', 'running', SendState::Sent, Disposition::Accepted, false, 'uncertain', 'renewal_confirmation_required'],
    ['quarantined claim', 'uncertain', SendState::Sent, Disposition::Accepted, true, 'uncertain', 'stale_claim'],
] as [$case, $state, $send, $disposition, $inTime, $next, $reason]) {
    same(['state' => $next, 'safe_code' => $reason],
        RenewalDecision::reply($state, 'stale_claim', $send, $disposition, $inTime), $case);
}

foreach ([
    ['exact payment', 1000, 1000, true],
    ['overpayment', 1000, 1010, true],
    ['partial reversal', 1000, 999, false],
    ['no payment', 1000, 0, false],
] as [$case, $total, $net, $funded]) {
    try {
        RenewalDecision::requireFunding($total, $net);
        $actual = true;
    } catch (RenewalHold $hold) {
        same('renewal_invoice_unpaid', $hold->reason, $case.' funding refusal');
        $actual = false;
    }
    same($funded, $actual, $case.' funding authority');
}
foreach ([
    ['partial reversal', '2027-10-18', 'paid', 999, 'held', 'renewal_invoice_unpaid', true],
    ['changed due date', '2027-10-19', 'paid', 1000, 'held', 'renewal_funding_or_authority', true],
    ['missing due date', null, 'paid', 1000, 'held', 'renewal_funding_or_authority', true],
    ['unfunded receipt', '2027-10-18', 'cancelled', 0, 'held', 'renewal_invoice_unpaid', false],
] as [$case, $due, $status, $net, $state, $code, $returnable]) {
    $reason = RenewalDecision::invoiceRefusal($due, '2027-10-18', $status);
    if ($reason === null) {
        try { RenewalDecision::requireFunding(1000, $net); }
        catch (RenewalHold $hold) { $reason = $hold->reason; }
    }
    same($code, $reason, $case.' refuses dispatch');
    $hold = RenewalDecision::hold($reason, false, $net > 0);
    same([$state, $code, true, $returnable],
        [$hold['state'], $hold['safe_code'], $hold['renewal_execution']['staff_alert'], $hold['renewal_execution']['refundable_later']],
        $case.' retains the paid obligation');
}
foreach ([
    ['ordinary charge', 'enom', 1, '8.00', '8.00', '10.00', '12.00', false, 'succeeded', null, 'confirmed', false, false],
    ['below cost', 'enom', 1, '8.00', '8.00', '10.00', '1.00', false, 'succeeded', null, 'below_cost', true, false],
    ['registrar correction', 'enom', 1, '8.00', '8.00', '10.00', '12.00', true, 'succeeded', null, 'confirmed', true, false],
    ['discount', 'enom', 2, '7.00', '8.00', '10.00', '12.00', false, 'succeeded', 'actual_charge_changed', 'actual_charge_changed', true, false],
    ['one-year overrun', 'enom', 1, '9.00', '8.00', '8.00', '12.00', false, 'succeeded', 'actual_charge_changed', 'actual_cost_overrun', true, true],
    ['multi-year charge drift', 'enom', 2, '9.00', '8.00', '10.00', '12.00', false, 'uncertain', 'actual_charge_changed', 'actual_charge_changed', true, true],
    ['multi-year overrun', 'enom', 2, '11.00', '8.00', '10.00', '12.00', false, 'uncertain', 'actual_cost_overrun', 'actual_cost_overrun', true, true],
    ['NameSilo multi-year', 'namesilo', 2, '9.00', '8.00', '10.00', '12.00', false, 'succeeded', 'actual_charge_changed', 'actual_charge_changed', true, false],
] as [$case, $registrar, $years, $actual, $authorized, $cap, $retail, $corrections, $state, $code, $reason, $alert, $pause]) {
    $result = RenewalDecision::settlement($registrar, $years, $actual, $authorized, $cap, $retail, $corrections);
    same([$state, $code, $reason, $alert, $pause, false], [$result['state'], $result['safe_code'],
        $result['renewal_execution']['reason'], $result['renewal_execution']['staff_alert'], $result['pause'],
        $result['renewal_execution']['refundable_later']], $case.' settlement');
}
$name = 'holding.example';
$binding = new AccountBinding('enom', 1, Environment::Test, 'fixture-account');
$foreign = new AccountBinding('enom', 2, Environment::Test, 'fixture-account');
$expiry = static fn (string $date) => new DateEvidence($date, ExpiryPrecision::Date, 'provider');
$query = new Query($name, Action::Renew, 1);
foreach ([
    ['unchanged expiry', new DomainSnapshot($binding, $name, true, $expiry('2027-10-18')), null],
    ['foreign account', new DomainSnapshot($foreign, $name, true, $expiry('2028-10-18')), null],
    ['different name', new DomainSnapshot($binding, 'other.example', true, $expiry('2028-10-18')), null],
    ['unowned', new DomainSnapshot($binding, $name, false, $expiry('2028-10-18')), null],
    ['unknown ownership', new DomainSnapshot($binding, $name, null, $expiry('2028-10-18')), null],
    ['no dated observation', new DomainSnapshot($binding, $name, true), null],
    ['later owned expiry', new DomainSnapshot($binding, $name, true, $expiry('2028-10-18')),
        ['kind' => 'advanced_expiry', 'expiry' => '2028-10-18', 'precision' => 'date']],
    ['matching order without charge', new OrderEvidence($binding, $query), null],
    ['charge on another account', new OrderEvidence($foreign, $query, new Money('8.00', 'USD')), null],
    ['observed charge', new OrderEvidence($binding, $query, new Money('8.00', 'USD'), 'order-123'),
        ['kind' => 'charged_order', 'amount' => '8.00', 'currency' => 'USD', 'reference' => 'order-123']],
] as [$case, $seen, $expected]) {
    same($expected, RenewalDecision::positiveObservation($binding, $name, '2027-10-18', $seen), $case.' retained proof');
}
$old = new DomainSnapshot($binding, $name, true, $expiry('2027-10-18'));
$later = new DomainSnapshot($binding, $name, true, $expiry('2028-10-18'));
$other = new DomainSnapshot($foreign, $name, true, $expiry('2027-10-18'));
foreach ([
    ['unchanged confirmed expiry', $old, $old, true, true, null],
    ['advanced info', $later, $later, true, true, 'advanced'],
    ['advanced dedicated expiry', $old, $later, true, true, 'advanced'],
    ['failed info', null, null, false, false, 'unconfirmed'],
    ['malformed info followed by an old expiry', null, $old, true, true, 'unconfirmed'],
    ['failed or malformed dedicated expiry', $old, $old, false, true, 'unconfirmed'],
    ['late dedicated expiry', $old, $old, true, false, 'unconfirmed'],
    ['foreign info', $other, $old, true, true, 'unconfirmed'],
    ['foreign last reply', $old, $other, true, true, 'unconfirmed'],
    ['positive reply before read failure', $later, $later, false, false, 'advanced'],
] as [$case, $info, $highest, $answered, $inTime, $refusal]) {
    same($refusal, RenewalDecision::negativeRead($binding, $name, '2027-10-18', $info, $highest, $answered, $inTime),
        $case.' negative support answer');
}
echo "Paid renewal admission, funding, send, reply and settlement decisions passed.\n";
