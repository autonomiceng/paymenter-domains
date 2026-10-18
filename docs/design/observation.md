# Dormant account observation (slice31)

Read-only Enom source provenance: `444f31ce0bfd07216e5200544bffe863119d0e7d`,
DomainObservation/details refresh semantics. Provider vocabulary remains in each adapter.
No installed schedule, sender, routes or default processing activation is introduced.

`ObservationWork` explicitly enrolls immutable accounts. Durable turns rotate even when
an account has a backlog or fails. Each account turn discovers at most25 holdings and
claims one due read job; claims expire after300seconds. New worker instances retain
cursors, due dates, attempts and leases. Holdings use their original account regardless
of current seller. Spending pauses do not prevent otherwise authorized account reads.

`DomainObservation` gathers real adapter facts outside transactions and rejects changed
holding/native/account/settings/evidence stamps before saving encrypted projections.
Both lock windows defer while a running operation has a live lease or a queued management
claim exists; background projection saves cannot invalidate in-flight management grants.
It never invokes financial reconciliation/completion, changes operations, confirms expiry,
activates services, advances billing anchors, refunds, releases reservations or sends writes.
Unknown facts remain null. Transfer terminal status is diagnostic only, even with charge facts.
Missing Enom TransferOrderID has no guessed domain-only recovery; staff recovery stays36/39.

Completion of register/transfer atomically records pending follow-up. An acquisition-key work phase preserves exhausted pending history. Three owned, consistent
snapshot observations mark it observed, with five-minute follow-up spacing; ordinary
active refresh is daily. This is observation progress, never fulfillment acceptance.
Healthy exact transfer Pending has a distinct seven-day/28-read window with six-hour
cadence, conditional on every required pending-domain/history read succeeding. Pending
ownership/history responses are not established vendor facts:39 must validate them and
avoid known failing reads (including registration-pending refresh) before installation.
Original operation poll/resume evidence remains untouched. Other unresolved
facts stop after five reads. Observation contexts stop at the first failed wire result, including partial logical reads.
Read failures hold immediately. Local admission refusals or discarded stale results defer
for60seconds without spending failure tries or resume budget. Internal explicit resumes
append a safe reason and allow at most20 per job, preserving the original pending window.
Staff authorization/diagnosis/alert policy and delivery remain35/36/38 before installation.

`ObservationCandidates` has independent durable account turns and ID cursors for refund
and missing-code candidates, at most100 IDs/account. Selection has no financial effect
or time-based debt expiry. Consumers39 must independently invoke the existing locked
credit/timeout authority; sent provider rejections are excluded from refund scans.
Held readmission, saved-code/never-paid dispositions and unpaid-retirement calendar policy
remain35/36; selecting an ID must never be interpreted as an authorization.

Separate management correction operations freeze the original key, generation and named
section alongside desired input; actual current funding/holding/input checks remain in
management authority. Appended readback entries retain the same link. Projections report
unresolved or readback-confirmed-awaiting-review, never automatically closed. Original
attempt.corrections stays byte-for-byte retained. Confirmed management reads project
section facts in durable domain readback sequence (including equal-clock/reverse-execution
requests) and merge known glue hosts; unknown/missing sections are never invented.

`AccountReadBudget` is an optional per-wire RequestContext port used by both read clients
and named management reads. Durable account-locked admission shares a local60-wire/minute
ceiling across origins. NameSilo support's2026-10-18 statement additionally caps `/api`
at one active request and one-second start spacing, `/apibatch` at two active requests.
Enom uses a conservative local serial ceiling, with no new vendor rate claim. Each wire
uses its remaining deadline and at most25seconds;30-second leases retain crashed slots.
Failure releases concurrency but consumes quota. No waiting, network retries or quota refunds.

Catalogue refresh optionally rotates one accepted suffix per account turn within the
shared1800-second cadence. Atomic claims prevent duplicate suffix admission. Failed imports
retain prior prices/freshness and hold until a bounded safe-reason resume (20/account);
crashed claims also hold. Local quota refusals defer without holding or advancing the cursor.
Publication on refresh/expiry and delivered outage alerts remain37/38/39.

Gate39 must verify ObservationSchema after Migrator even when migration up is skipped;
down retains all three tables. Partial/mismatched schema refuses. Storage.md is already
at its800-line cap; integration must consolidate this declaration into its versioned schema.
Installed composition must inject the same limiter into every read context, coordinate
reads and writes under accepted vendor caps (writes take the same lease, refusal definitely
unsent), decide interactive headroom before32 injects the port, and prove shared-cache/transport/timeout,
clock-skew/process-pause/lease semantics and paired MariaDB current-lock races. These
sequential synthetic SQLite checks do not establish distributed/provider acceptance.

Earlier correction operations without a frozen link remain unresolved; Enom uses the same
existing separately authorized correction checks. Original attempts are never retrofitted.
Legacy readbacks without a sequence retain timestamp/ID ordering;39 must classify ambiguous
legacy section/glue history Unknown before any installed presentation relies on it.
