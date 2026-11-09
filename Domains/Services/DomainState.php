<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** One customer domain's state, built by DomainStates::project. The Domains list and the domain page both read it,
 * so their status, order, renewal setting and renewal invoice agree.
 * status: [state, reason] for StatusPill. order: OwnedDomains::order. stops: the RenewalStop read ledger.
 * renewal: '' (no renewal row), 'On' or 'Off'. renewalOff: future renewals stop (an import still being confirmed counts).
 * cycle: the current renewal invoice as OwnedDomains::renewalCycle words it (['invoice' => url] or ['status' => ...]), or null.
 * retained: the status a settled earlier invoice for this expiry keeps when nothing replaces it, or null.
 * due: the current invoice's due date. early: the current invoice is unpaid and its issue date is still ahead.
 * calendar: the saved registrar calendar for the next invoice (expiry, issue, due), or null without one.
 * imported: an imported domain's next invoice is past its due date and was never issued, so we check it. */
final readonly class DomainState
{
    public function __construct(public array $status, public array $order, public ?Operation $acquisition, public array $stops,
        public string $renewal, public bool $renewalOff, public ?array $cycle = null, public ?array $retained = null, public ?string $due = null,
        public bool $early = false, public ?array $calendar = null, public bool $imported = false) {}
    /** The renewal invoice a reader without a Renew now action sees: the current one, a settled one, or an imported domain's missing one. */
    public function invoice(): array
    { return $this->cycle ?? $this->retained ?? ($this->imported ? ['status' => 'review'] : []); }
}
