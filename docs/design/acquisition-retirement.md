# Unpaid acquisition retirement

Managed catalogue products always have `stock = null`, including withdrawn offers. Publication
removes acquisition prices; locked checkout admission checks owned eligibility again. A retained
migration converts legacy zero/positive stock to null. Scoped model saves refuse finite stock
with a staff validation message and log. Native increment/decrement uses SQL null arithmetic and
never throws for managed stock. Raw/bulk/quiet maintenance is outside model-event guarantees.

The native old-Pending cron may cancel an unpaid managed Service and its entire pending invoice,
including hosting items on that invoice. Invoice cancellation is a bulk update without model
events. A Service after-commit wake is an optimization; the account-fair, 25-domain runtime cursor
also checks retirement. Old Pending orders with financial/send uncertainty retain a durable staff
review alert, even when the native cancellation guard refuses the unsafe write.

Retirement joins one outer transaction using HoldingLocks: native user/staff/credit, invoices,
items, transactions, orders/services/products/plans/cancellations/intents/configuration, then
account/catalogue/domain/operations. It accepts exactly one registration or transfer operation,
its frozen checkout identity, Pending/Cancelled invoice and service, zero invoice transactions,
no funding/claim/lease/attempt/dispatch/provider evidence and an existing definitely-unsent proof.
Any transaction (including failed, reversed or processing), other operation or uncertain send
requires staff review and retains the name. The automatic path additionally requires native
Service and invoice cancellation. No observation, elapsed timeout or invoice status alone proves
that a registrar request was unsent.

The same locked proof backs the permission-scoped **Retire unpaid order** action. Staff supplies
a one-line reason and an actor/domain/action-bound 15-minute review key. Current permissions and
all reviewed rows are checked again under locks. A pending invoice with other items refuses;
staff must use the native whole-invoice cancellation workflow first. An already cancelled shared
invoice is accepted only with zero transactions. The action cancels the service and sole pending
invoice, appends encrypted actor/reason/before/after evidence to the operation policy, records a
bound unfunded rejected envelope, closes the owned acquisition and clears its reservation in the
same transaction. The audit and native cancellation intent remain retained. No credit or refund
is fabricated. Replays and changed review snapshots refuse.

The unfunded release method requires the full locked native proof; the generic name-only release
path still requires account-credit evidence for paid acquisitions. Terminal recognition requires
the exact unfunded envelope and matching authority. Late money or native Active after retirement
records a durable discrepancy; it never reopens work, reclaims a name or throws in the paid loop.
Late-money alerts require staff reconciliation. Successful proven retirement resolves an earlier
retirement-refusal alert; ordinary reminder passes cannot clear either kind. An advisory no-money
check avoids the full financial lock prefix on quiet retired orders; a later cursor pass retries misses.

An unpaid Pending managed service has no paid period to finish. Public cancellation creation or
update normalizes end-of-period to immediate before native persistence. The queued native immediate-cancellation listener also bulk-cancels the
whole pending invoice, including hosting items; its post-service bulk update is recovered by the
sweep. A cancelled service with an open invoice retains its name and alerts staff. The old Pending
review age follows the native configured cancellation delay. Completed holdings keep
their existing end-of-period behavior. Raw maintenance remains privileged. Native cancellation,
cron and Eloquent stock internals are pinned by the test-only upgrade tripwire, without a runtime
version gate. The native paid activation/removal work remains in later Option E slices.

`write_acceptance_required` and `WriteHeld::UNSENT_REFUSALS` join the existing pre-seal interruption recovery allowlist. Recovery
still requires the unchanged absent-seal/definitely-unsent claim proof; present-null execution
or dispatch evidence refuses. The existing refund-once and terminal encoders retain their locks.

Closed paid acquisitions use the same durable late-money alert as unpaid retirement. A guarded completed cancellation or staff refund authorization retains an immutable payment snapshot in the acquisition policy before deferred credit/wakes. After-commit checks and the fair sweep compare subsequent payment activity and native Active against that evidence; each distinct activity gets an alert identity. Historical refunded payments and ordinary completed cancellation stay quiet. Review shows invoice/request links according to staff permissions and never sends, credits or releases a name. Missing native Service events pause acquisition sends; missing Paid delivery falls back to the sweep. Native paid processing now runs directly, without a paid-invoice adapter.
The first accepted native cancellation also retains its own immutable timestamp beside the closure snapshot. That distinguishes reactivation during a pending staff credit from the original paid Active state without changing the original payment baseline.
