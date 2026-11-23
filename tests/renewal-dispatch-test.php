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
