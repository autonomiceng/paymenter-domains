<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Data\{Disposition, SendState};
use Paymenter\Extensions\Servers\Domains\Services\{RenewalDispatch, RenewalHold};

foreach ([
    ['awaiting_payment', true, true],
    ['queued', true, true],
    ['queued', false, false],
    ['running', true, false],
    ['pending', true, false],
    ['uncertain', true, false],
    ['held', true, false],
    ['succeeded', true, false],
] as [$state, $virgin, $allowed]) {
    same($allowed, RenewalDispatch::canAdmit($state, $virgin), "Admission for $state with virgin=".(int) $virgin);
}

$now = new DateTimeImmutable('2027-10-01T12:00:00Z');
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
    same($expected, RenewalDispatch::canSend(4, $revision, $state, $matches, $funded,
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
        RenewalDispatch::reply($state, 'stale_claim', $send, $disposition, $inTime), $case);
}

foreach ([
    ['exact payment', 1000, 1000, true],
    ['overpayment', 1000, 1010, true],
    ['partial reversal', 1000, 999, false],
    ['no payment', 1000, 0, false],
] as [$case, $total, $net, $funded]) {
    try {
        RenewalDispatch::requireFunding($total, $net);
        $actual = true;
    } catch (RenewalHold $hold) {
        same('renewal_invoice_unpaid', $hold->reason, $case.' funding refusal');
        $actual = false;
    }
    same($funded, $actual, $case.' funding authority');
}
echo "Paid renewal admission, funding and reply decisions passed.\n";
