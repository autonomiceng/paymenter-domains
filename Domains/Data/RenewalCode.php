<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
enum RenewalCode: string
{
    case Admitted = 'renewal_admitted';
    case Authority = 'renewal_authority_invalid';
    case Anchor = 'renewal_anchor_changed';
    case Prior = 'renewal_prior_outcome_unresolved';
    case Paused = 'automatic_spend_paused';
    case Acceptance = 'renewal_acceptance_required';
    case Rate = 'renewal_rate_limited';
    case Changed = 'renewal_binding_changed';
    case Reads = 'renewal_reads_unavailable';
    case Owner = 'provider_membership_unconfirmed';
    case AutoRenew = 'provider_auto_renew_unconfirmed';
    case Restore = 'renewal_restore_refused';
    case State = 'renewal_state_unconfirmed';
    case Term = 'renewal_term_refused';
    case Grace = 'grace_capability_unaccepted';
    case Cost = 'renewal_cost_unconfirmed';
    case Balance = 'registrar_balance_insufficient_or_unknown';
}
