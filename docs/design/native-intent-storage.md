# Native lifecycle intent storage

`domains_native_intents` is owned extension storage: primary service_id (unsigned bigint),
kind (cancellation/status), created_at. No native foreign key or cascade, credentials, reason,
provider payload or registrar mutation is stored. The migration retains rows on rollback and
installation verifies schema/history before upgrades. Existing data is not backfilled silently.

A creating cancellation or protected service-status update current-reads invoices, items and
transactions before ordered orders/services, then intent, domain and operation rows. Native
cron can already hold invoices, so the guard never takes a later user lock. It joins an outer
transaction or commits its entire short transaction within the public model event. Terminal
proof uses the existing original-attempt resolver, never a state label. Active is unguarded.

An allowed unpaid acquisition write commits intent before returning to native SQL. Claim/seal
and holding funding checks current-read the same intent under service locks. Claim-first is
refused by the event; intent-first is refused by claim. No transaction spans creating/created.
A failed INSERT leaves the intent retained and blocks spending, even if payment arrives later.
There is deliberately no automatic/staff acknowledgement clearing: a paused native request
might still insert. Retirement/refund recovery must preserve existing unsent proof and lifetime
rules; a renewal preference never readmits a fenced initial acquisition. This is an availability
tradeoff; it is not terminal, release or refund proof. An outer rollback rolls intent back too.

Raw/bulk/quiet writes remain privileged maintenance. Missing schema disables only guarded
lifecycle writes with a staff/customer validation error; native payment still sets Active.
The adoption-off deletion/checkout race documented by A0 remains open and adoption stays off.

A routed service without a ledger can also retain intent; later adoption must treat it as
held input, never discard it. End-of-period cancellation of an unpaid acquisition can therefore
leave later payment held for late-money review. Native callbacks in a transaction nested deeper
than the outer level fail closed at the existing lock seam; no qualified native caller uses it.

Unpaid Pending managed cancellation creation/update now normalizes end-of-period to immediate
before persistence. This supersedes the end-of-period unpaid example above. Retirement retains
intent and uses the separate locked unfunded proof described in `acquisition-retirement.md`.
