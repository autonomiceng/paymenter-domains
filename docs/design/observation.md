# Dormant account observation (slice 31)

Read-only Enom source provenance: `444f31ce0bfd07216e5200544bffe863119d0e7d`,
DomainObservation/details refresh semantics. Provider vocabulary remains in each adapter.
No installed schedule, sender, routes or default processing activation is introduced.

`ObservationWork` explicitly enrolls immutable accounts. Durable turns rotate even when
an account has a backlog or fails. Each account turn discovers at most 25 holdings and
claims one due read job; claims expire after 300seconds. New worker instances retain
cursors, due dates, attempts and leases. Holdings use their original account regardless
of current seller. Spending pauses do not prevent otherwise authorized account reads.

`DomainObservation` gathers real adapter facts outside transactions and rejects changed
holding/native/account/settings/evidence stamps before saving encrypted projections.
Both lock windows defer while a running operation has a live lease or a queued management
claim exists; background projection saves cannot invalidate in-flight management grants.
It never invokes financial reconciliation/completion, changes operations, confirms expiry,
activates services, advances billing anchors, refunds, releases reservations or sends writes.
Unknown facts remain null. Transfer terminal status is diagnostic only, even with charge facts.
Missing Enom TransferOrderID has no guessed domain-only recovery; staff recovery stays 36/39.

Completion of register/transfer atomically records pending follow-up. An acquisition-key work phase preserves exhausted pending history. Three owned, consistent
snapshot observations mark it observed, with five-minute follow-up spacing; ordinary
active refresh is daily. This is observation progress, never fulfillment acceptance.
Healthy exact transfer Pending has a distinct seven-day/28-read window with six-hour
cadence, conditional on every required pending-domain/history read succeeding. Pending
ownership/history responses are not established vendor facts: 39 must validate them and
avoid known failing reads (including registration-pending refresh) before installation.
Original operation poll/resume evidence remains untouched. Other unresolved
facts stop after five reads. Observation contexts stop at the first failed wire result, including partial logical reads.
Read failures hold immediately. Local admission refusals or discarded stale results defer
for 60seconds without spending failure tries or resume budget. Internal explicit resumes
append a safe reason and allow at most 20 per job, preserving the original pending window.
Staff authorization/diagnosis/alert policy and delivery remain 35/36/38 before installation.

`ObservationCandidates` has independent durable account turns and ID cursors for refund
and missing-code candidates, at most 100 IDs/account. Selection has no financial effect
or time-based debt expiry. Consumers 39 must independently invoke the existing locked
credit/timeout authority; sent provider rejections are excluded from refund scans.
Held readmission, saved-code/never-paid dispositions and unpaid-retirement calendar policy
remain 35/36; selecting an ID must never be interpreted as an authorization.

Separate management correction operations freeze the original key, generation and named
section alongside desired input; actual current funding/holding/input checks remain in
management authority. Appended readback entries retain the same link. Projections report
unresolved or readback-confirmed-awaiting-review, never automatically closed. Original
attempt.corrections stays byte-for-byte retained. Confirmed management reads project
section facts in durable domain readback sequence (including equal-clock/reverse-execution
requests) and merge known glue hosts; unknown/missing sections are never invented.

`AccountReadBudget` is an optional per-wire RequestContext port used by both read clients
and named management reads. Durable account-locked admission shares a local 60-wire/minute
ceiling across origins. NameSilo support's 2026-10-18 statement additionally caps `/api`
at one active request and one-second start spacing, `/apibatch` at two active requests.
Enom uses a conservative local serial ceiling, with no new vendor rate claim. Each wire
uses its remaining deadline and at most 25seconds; 30-second leases retain crashed slots.
Failure releases concurrency but consumes quota. No waiting, network retries or quota refunds.

Catalogue refresh optionally rotates one accepted suffix per account turn within the
shared 1800-second cadence. Atomic claims prevent duplicate suffix admission. Failed imports
retain prior prices/freshness and hold until a bounded safe-reason resume (20/account);
crashed claims also hold. Local quota refusals defer without holding or advancing the cursor.
Publication on refresh/expiry and delivered outage alerts remain 37/38/39.

Gate 39 must verify ObservationSchema after Migrator even when migration up is skipped;
down retains all three tables. Partial/mismatched schema refuses. Storage.md is already
at its 800-line cap; integration must consolidate this declaration into its versioned schema.
Installed composition must inject the same limiter into every read context, coordinate
reads and writes under accepted vendor caps (writes take the same lease, refusal definitely
unsent), decide interactive headroom before 32 injects the port, and prove shared-cache/transport/timeout,
clock-skew/process-pause/lease semantics and paired MariaDB current-lock races. These
sequential synthetic SQLite checks do not establish distributed/provider acceptance.

Earlier correction operations without a frozen link remain unresolved; Enom uses the same
existing separately authorized correction checks. Original attempts are never retrofitted.
Legacy readbacks without a sequence retain timestamp/ID ordering; 39 must classify ambiguous
legacy section/glue history Unknown before any installed presentation relies on it.

## Initial record inventory (31R)

The optional `OwnerPorts` on `DomainObservation` injects the accepted named discovery
port; absent composition does not produce a record inventory. Existing account-fair work
and 25-wire/250-second contexts budget all domain and record reads together. No GET
transport, scheduler, listener, sender or processing activation is registered. 39 owns that
composition and the existing publication/headroom/installed database acceptance gates.
Enom uses existing GetDomainInfo identity, GetHosts, GetDomainSRVHosts, GetDnsSec and
CheckNSStatus parsers from frozen 444f31c. NameSilo advanced discovery remains unknown:
its documented commands do not supply accepted completeness/identity/shape contracts.
Glue cannot be enumerated by the Enom source; at most 13 known hosts are refreshed in rotating order, with
confirmed local history retained as partial across unrelated or child-host read failures.
A failed record/child read sets an identity-bound encrypted `observation.record_hold`
with reason/time. Later daily ownership/expiry/drift reads continue but skip record discovery.
The existing bounded audited work resume clears the record hold for one new attempt;
only exact absence removes a child host. Neither driver claims complete glue discovery.
Before 39 registers this producer, a current-identity `record_hold` must surface as a
staff review/alert with its reason. Registration is held until that operational signal exists.

Encrypted `observation.records` contains domain/name/provider/account/server/generation,
provider domain ID, observation time, readback sequence watermark and per-family state.
Only explicit complete record lists (including positively proven empty) unlock DNS/SRV.
Unknown/malformed/failed families replace prior completeness; stale identity is discarded.
Later sequenced management readbacks overlay the observation; older or ambiguous legacy
reads cannot replace it. Live dispatch still independently verifies the whole-list preimage.
No billing anchor, confirmed expiry/revision, operation or financial authority changes.

Owner presentation scans at most 100 current-generation operations per projection and
100 progress entries; correction projection also caps 100. Advisory native validation reads
at most 101 operations and holds forms when the bound is exceeded. Those GET checks use
no row locks, including credential settings; POST/consume retain the ordered locking
protocol. Large histories need 39 retention-preserving compaction rather than unbounded
GET decryption. Above 100 operations or 50 known child hosts, refresh skips record
discovery/publication and holds only the record stage for explicit resume. Healthy domain
observations keep their schedule even after the existing 20-resume budget is exhausted. It never
advances a record watermark past omitted child-host evidence. Record quota deferral saves
successful domain facts and leaves inventory unchanged. SQLite checks prove lock-free query construction, not MariaDB concurrency.

Frozen MIT provenance: Enom `444f31ce0bfd07216e5200544bffe863119d0e7d`,
`DomainOperations::refreshDomain`, `EnomApi::snapshot` and `currentHostIp`; source
`tests/Enom/glue-records.php`, `provider-contracts.php`, `capability-readbacks.php`
and `automatic-refresh.php`. Existing strict parsers are reused; native initial-inventory
cases cover empty/unknown, identity races, partial glue retention and sequence ordering.

A wholly failed primary observation leaves the saved inventory and successful fact timestamps intact.
Separate identity-bound `record_failure` evidence blocks DNS/SRV whole-list forms and owner POSTs;
it survives staff resume and clears only when new record inventory is saved. The current record hold
stops discovery until resume, including when the primary failure follows an existing partial-record hold.
Healthy primary reads with failed record families retain the complete/unknown publication rules above.
