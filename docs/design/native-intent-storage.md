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
The User deletion/checkout gap is closed by the separate owner intent below. Adoption stays off pending site qualification.

A routed service without a ledger can also retain intent; later adoption must treat it as
held input, never discard it. End-of-period cancellation of an unpaid acquisition can therefore
leave later payment held for late-money review. Native callbacks in a transaction nested deeper
than the outer level fail closed at the existing lock seam; no qualified native caller uses it.

Unpaid Pending managed cancellation creation/update now normalizes end-of-period to immediate
before persistence. This supersedes the end-of-period unpaid example above. Retirement retains
intent and uses the separate locked unfunded proof described in `acquisition-retirement.md`.

## Customer deletion

`domains_user_deletions` retains user_id (unsigned bigint primary key) and created_at.
It has no native foreign key, cascade or customer data. Migration 000036 creates and
verifies this table without backfill. Rollback retains it. Install the migration before
reopening checkout; missing storage refuses customer deletion and domain acquisition.
Ordinary hosting checkout is unchanged.

The User deleting guard locks sorted original/proposed users, invoices, orders, services,
domains and operations, then the intent. This follows the holding prefix; it never takes
an invoice after an order or service. Retained rows and intent use current locking reads,
including inside an older repeatable-read transaction. Direct domain ownership,
service ownership and the order owner of routed services all prevent deletion. Retained
rows remain protected regardless of domain status or a released reservation.

Checkout already owns its user lock before checking intent. Adoption checks it in the
shared holding prefix immediately after sorted users. The intent table is touched only
under its user lock; no path takes that user lock after intent. Checkout-first commits a
holding that makes deletion refuse. Intent-first makes checkout and adoption refuse.
Deletion joins a caller's outer transaction or commits its own short transaction within
deleting. Nested callers refuse. No transaction spans deleting/deleted.

If native DELETE or a later listener fails, the marker stays. Domain acquisition for that
customer remains blocked; staff must retry and finish customer deletion. Retries recheck
retained rows without changing the marker. An outer rollback restores both user and
intent. Completed deletion also retains the marker: concurrent deletion requests may
still resume, so manually reusing that numeric ID must not admit a new domain owner.
Normal new customers receive new IDs. This is an availability tradeoff for failed deletion,
not terminal domain or refund evidence; renewal and cancellation use their existing rules.

Do not expire or manually clear an abandoned intent. A paused native request can resume
its DELETE later. A cancellation action would need proof that every original deletion
request has ended; that recovery remains deferred. Existing orphan diagnostics remain
available for raw, bulk and event-suppressed maintenance bypasses.
