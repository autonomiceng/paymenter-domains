<?php
// Independent completion identity/evidence cases; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
foreach($completionCases as $case){
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
