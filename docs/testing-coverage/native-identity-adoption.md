# Native identity and adoption coverage

## Paymenter upgrade re-review

`tests/data/paymenter-upgrade-hashes.json` records exact approved core/vendor bytes by
Paymenter version and names each dependent Domains contract. The native suite runs
`tests/paymenter-upgrade-tripwire-test.php`; optional checkout and version arguments
inspect a copied candidate directly, before the pristine fixture guard accepts it.
Changed/missing dependencies and added PHP writers under native ServerResource,
ServiceResource and invoice services fail with a named dependent-file/re-review list.
The two explicit `config/app.php` hashes cover upstream's development marker and the
fixture's exact 1.5.9 release substitution; no other normalization is performed.

For a candidate, retain the old version's manifest, inspect upstream diffs for every
reported path and newly added writer, and document the decision for each affected
contract. Re-run native behavior, install/retention, secrecy, mixed invoice/funding,
renewal and raw-input tests against that candidate. Only then add a separately reviewed
version entry and its immutable hashes: **never bulk-refresh hashes to turn CI green**.
Matching hashes are containment evidence, not permission for global infrastructure
replacement or proof of provider, production database, theme or deployment acceptance.

These fingerprints and the checker are test-only; the extension never loads them.
They fail suite/CI qualification against the installed Paymenter tree, and impose no
runtime version check, enablement refusal, dispatch refusal or staff banner.

Owned transfer-code and DNS/SRV handlers decode the raw POST body with `Http/RawPost`.
The shipping Blade forms (`resources/views/partials/transfer-code.blade.php` and
`resources/views/partials/owner-{dns,srv}.blade.php`) use ordinary POST forms with
no enctype override or JavaScript submission: browsers send form-urlencoded bodies.
JSON object bodies are also decoded explicitly; JSON form keys use Laravel's normal
input API. Neither parser takes byte-sensitive fields from query parameters or falls
back to transformed input bags. Multipart bodies, malformed JSON, oversized bodies and
form input that would exceed PHP's field limit are refused. No global TrimStrings
exceptions remain. Native tests run trimming and empty-string conversion before the
owned handler, check exact encrypted transfer/TXT bytes and safe SRV rejection, and
prove an unrelated core route's `transfer_code` is trimmed. Routes retain web CSRF,
authentication, verified-email, throttle and session-blocking boundaries.

Invoice description cases in `Support/invoice-descriptions.php` run through `invoice-binding-test.php`.
They cover all four charges, multi-year and leap-day ranges, Unicode display and middle truncation
at the native 255-character limit; dates and qualifiers always survive. Native binding, renewal
billing and paid privacy tests check issued text stays frozen when registrar expiry changes.
`invoice-lines-render.php` exports synthetic formatter examples for wording review, not installed
invoice/theme acceptance. Privacy purchase dates are expected from invoice day; privacy renewal
uses its own frozen subscription expiry, independently of the domain's term.

`adoption-storage-test.php` verifies retained adoption requests and append-only invoice-history links,
request/committed-generation/link uniqueness, encrypted hidden evidence, frozen intent, stale-preview
refusal, skipped-migration schema verification and uninstall/reinstall/down retention. Storage fixtures
create no holdings, operations or invoices; adoption authority and native history validation stay deferred.

Renewal admission tests for both registrars run the shared `Support/renewal-acceptance.php` matrix:
accepted and sales-withdrawn endings, renewal-safe/annual-term revocation, missing endings, revocation between
check and refresh and during provider reads. Refused entry and refresh make zero additional wire calls.
All exchanges remain synthetic; SQLite does not establish concurrent MariaDB acceptance.

### A0 native adoption lock prerequisite

`holding-identity-lock-test.php` covers the outer-transaction-only identity API, sorted current
Order locks before Service locks after the existing owner/invoice prefix, owner/relation
mismatches, stale preview ownership and context expiry, including connection loss. Paused
competitors commit before adoption: once before the existing ledger guard, and once after
the guard but before SQL. The API then refuses stale identity. SQLite checks requested row locks and ordering, not
database-level concurrent row-lock behavior. `holding-identity-writers-test.php` checks
Order-owner drift refusals and the same lock order in holding, funding and contact recovery.
Adoption stays off. The lock API alone does not prevent an unlocked identity edit committing
after it; activation also needs the separately reviewed native mutation protocol and worker drain.

A3 must abort the entire outer transaction on any database exception; catching a nested
deadlock and continuing is unsupported. MariaDB transaction-status behavior is unverified.

### A0 native write-path pinned-fixture tripwire

`native-identity-inventory-test.php` pins Paymenter 1.5.9 files under `app`, `routes`,
`database/migrations`, `config` and bundled `extensions`, plus dependency/bootstrap files
and Filament persistence actions. The manifests record hashes and each audited identity
write path's model-event or exclusion disposition. Changed, missing and added files in
the runner's pinned fixture fail with names for re-review when the native pin changes.
This does not detect deployed upgrades or newly installed extensions: later activation
requires evidence of the deployed version and installed extensions. No core bulk UPDATE
of Order ownership or Service owner/order/product/plan was found. Product settings,
config and User-property bulk writes, pivot writes, FK cascades and destructive import
are recorded separately. FK effects bypass child events; ordinary parent deletion events
need an explicit refusal policy before activation. Raw SQL,
query-builder bulk writes, direct PDO and deliberate event suppression retain the
privileged-maintenance exclusion in storage's Reservation authority and Account binding
authority sections. No activation safety is claimed beyond that boundary. Adoption stays off.

### A0 route identity refusals (adoption remains off)

`native-identity-test.php` checks ordinary event-enabled Order/Service, canonical-domain Property,
ServiceConfig and route Product/Plan/marker writes, including original and proposed identities,
malformed routes, partial model projections and native parent deletion/cascade paths.
Dirty checks and identity reconstruction account for case-insensitive and qualified SQL identifiers.
Persisted/proposed values are compared using model casts even when projected numeric extras are clean.
Persisted rows supplement loaded original/proposed attributes; select projections cannot omit identity.
The reserved `domains` category is retained before first publication, including partial-model deletion. Protection uses route identity before any
ledger exists. Unsupported model identity edits and managed-route service deletion are refused.
Native status changes, including paid `Active`, remain allowed; managed-route `expires_at` is silently
cleared to null by ordinary saves. Event-enabled instance increment/decrement extra attributes are
also checked through updating. A non-null route expiry in those unsupported numeric writes is refused
with the logged safe message because Eloquent retains the original SQL extras. The installed app,
extensions and vendor audit found no Service/Order numeric caller with extra attributes; native stock,
credit and affiliate counters remain allowed. Full expiry handling belongs to the later lifecycle slice.
Initial invoice items retain their native Service morphs. Unsafe paid-cancellation
enforcement remains an open prerequisite for the separately planned cancellation slice.

The controlled adoption-first competitor pauses before the guard, commits a synthetic lock-and-insert,
then resumes and is refused. A post-guard/pre-SQL sentinel is unreachable for protected edits and is
reached by ordinary hosting. Paired commit-first cases are in `holding-identity-lock-test.php`.
SQLite checks requested lock order and scheduling; it does not prove concurrent MariaDB row locking.
`native-identity-readiness-test.php` renders the installed staff adoption-off/drain/worker-restart fact.
Before activation, drain in-flight native identity edits and restart web/queue workers; an observer
cannot retroactively guard a write already running in an older worker.

The privileged-maintenance boundary in `docs/design/storage.md` (Reservation authority / Account binding authority)
still excludes raw SQL, direct PDO, query-builder/bulk writes and quiet/event-suppressed saves. Tests
explicitly demonstrate those bypasses. This guard makes no activation-safety claim beyond that boundary.
Existing routed children are guarded at their event-enabled parent; core configuration
builder deletes and form pivot syncs listed in the inventory remain outside model-event interception.

Event-enabled User deletion and admitted checkout serialize through the native user lock and retained deletion intent.
`native-identity-cascade-test.php` asserts the exact pending-deletion reason and no holding in the former orphan schedule.
`user-deletion-test.php` covers retained rows, ownership routes, obligations, retries, rollback, missing schema and the real staff notification.
Adoption refuses marked owners before creating a holding. SQLite proves scheduling and requested locks, not row-lock waits.

`test_user_deletion_mariadb.py` uses two native PHP processes against local, socket-only MariaDB, with plain and prefixed tables.
It covers intent-first, checkout-first with an observed InnoDB user lock wait, and deletion from an older repeatable-read snapshot.
This opt-in test makes no provider calls. Adoption stays off pending site checks of native edits, installed consumers, registrar acceptance and managed public DNS.

Post-delete User/Service observers inspect at most 25 retained domains and save a staff discrepancy;
the existing durable runtime domain cursor inspects at most 25 domains per account turn to recover
missed/overflow checks while the saved account binding remains current. The Registrars page lists the saved alert under Missing Paymenter records and shows it even when native detail cannot open.
These checks are diagnostic, grant no authority and do not recreate native records.
The staff value lists domain names and states that their names remain reserved; no resolution action is promised.
Runtime registration checks conversion completion before installing listeners, so refused boot cannot block parked conversion.
`native-identity-orphans-test.php` checks missing User/Order/Service before claim and during unlocked
preflight: no provider send or seal, no live claim after refusal, no contact readmission, and native
money/jobs unchanged. Healthy/restored controls succeed. Missing identity also refuses the holding
and adoption-lock APIs. This is fail-closed at authorization checks, not recall of a provider request
already sent before a later deletion. SQLite scheduling does not prove MariaDB concurrency.

The same absence race can null a newly assigned Service coupon during concurrent coupon deletion;
it changes coupon accounting but does not reassign ownership or create an orphan. It is a retained
limit for later review. Parent/reference scans use bounded model batches but still query route
identity per Service; large hosting-product identity edits may remain slow.

### Exact native Service projections

`native-projection-test.php` checks admitted checkout, funded initial completion, owned annual
pricing and definitely-unsent refund projections. Synchronous private grants bind the exact
Service instance, row ID, original attributes, changed fields and new values. Saving consumes
each grant once; updating rechecks it and finally closes it on success or failure. A savepoint
and exact post-save check contain later updating callbacks, even if callers catch the refusal. Clones,
fresh models, changed values/fields/preimages and nested saves cannot inherit a grant. Order
identity remains immutable; these writers never project an Order. Native price edits require
owned pricing even when the proposed amount equals current retail.

The real native paid renewal handler still writes Active without a capability and its expiry
is neutralized to null. Paused owned/native schedules preserve each other's unrelated writes.
These are synthetic SQLite schedules and requested-lock checks, not paired MariaDB concurrency
acceptance for those projections. User deletion/checkout has separate paired MariaDB coverage above. Privileged-maintenance exclusions remain.

Owned completion/terminal proof checks gate the owned decision; native billing status remains
independently writable. Price protection checks model dirtiness so a clean native instance loaded
before owned repricing can still activate without overwriting retail. A same-second checkout
clock rollover cannot change the preplanned timestamp. Caught refusals restore the locked preimage.
For Option E these rules supersede the legacy fresh-retail native-price acceptance described in
`docs/design/storage.md`: every dirty native price edit requires an owned projection.

## Closed adoption readback

`adoption-enom-test.php` uses synthetic XML and a closed fake transport. It checks the
four-command adoption vocabulary, every excluded general read and modeled mutation
individually, injected parameters, ownership/ID/expiry parsing, renewal and hold
refusals, six-request budget, deadline and per-wire bounds. No account calls occur.

## Fresh adoption preview

The native adoption preview suites cover exact customer/order/annual registration mapping, pending identity/expiry,
current permission and site/environment guards, unlocked-read changes, encrypted evidence, proof-aware operation
history, unchanged invoices/cancellations and shared retail/calendar rules. The staff suite exercises the stock
Filament page, every safe refusal, TEST/live badges, escaped HTML and disabled confirmation; it proves no activation.

DNS reader coverage uses only injected native callables and a fake `DnsReader`. Empty/failed
native answers remain unknown: PHP empty arrays are ambiguous (including type-filtered CNAME gaps). The shared
45-second context admits at most ten queries and rejects late results; it cannot interrupt
a blocking system resolver call. DoH adapter for proven empty answers is a follow-up;
proven NODATA/NXDOMAIN is supported without relaxing native unknowns. Worker isolation is deferred.

Watch DNS storage tests cover default-off profiles/work, exact revision and owner/source stamps,
claims/expired workers, encrypted retained facts, independent axes and failed-read stale projections.
They use a disposable SQLite fixture; concurrent MariaDB activation and dispatch remain unqualified.

DNS signal tests cover exact complete NS sets, proven NODATA/NXDOMAIN through a fake reader,
null MX, mixed overlap, bounded CNAME chains, omitted hosts and failed-read empty-label refusal.
TTL/order changes preserve the routing digest; source/profile changes discard in-flight results.
The native reader stays conservative; see `docs/design/watch-dns.md` for activation limits.

`native-lifecycle-test.php` covers both guard boot orders, numeric expiry refusal and numeric
updates without expiry extras, native paid Active/null saves, explicit idempotent legacy-date
cleanup, scoped editor notice, unpaid native cancellation/status, terminal-proof refusal and
acceptance, and the creating-check/INSERT payment race. Fake transport only; committed intent
prevents any claim in that gap and survives a later native callback failure. SQLite scheduling
is diagnostic evidence, not InnoDB concurrency or installed-theme acceptance.

### Owned holding changes

`staff-holding-test.php` exercises audited reprice/stop/resume, current permission and stale/reused/expired review refusals, native end-of-period resume and retained intent refusal. It renders real owned modals and proves issued debt stays unchanged and a later paid renewal executes/completes through a future-only stop. Synthetic event schedules and SQLite do not establish InnoDB concurrency acceptance.

Renewal credit cases cover global on/off/unset, ignored retained server settings, exact/insufficient balances,
native credit transactions and remaining-only settlement after partial, full or excess earlier payment.

## Holding adoption storage

`holding-adoption-test.php` uses native synthetic fixtures to attach a committed
reverse-linked adoption under the existing identity prefix. It covers expired locks,
one-way linkage, noncommitted/terminal rows, pointer and every original holding field
freeze, numeric-update refusal, ordinary revision/expiry/observation/anchor evolution,
retained migration and missing-column verification. It adds no confirmation action
and makes no provider calls. Shared-database concurrency and activation remain open.

### Adopted renewal authority

- `adopted-renewal-test.php`: a live-bound native holding with no acquisition
  operation issues/funds a renewal invoice, executes through an intercepted fake
  sender, completes two cycles, advances the verified anchor and retains original
  snapshot/evidence bytes and pointer.
- `adopted-renewal-refusals-test.php`: independent link, fingerprint, generation,
  stale/unconfirmed revision, expiry, provider ID, owner/order relation, ciphertext
  and missing-holding cases; explicit staff-review projection and adversarial
  purchase-guard separation. Refusals create no obligation or provider work.
- `adopted-test-environment-test.php`: internally consistent TEST account, adoption
  and holding evidence still cannot anchor renewal.
- Existing checkout, funding, acquisition, management and observation suites remain
  the regression coverage for the genuine checkout branch.
- `adopted-renewal-history-test.php`: frozen, proved-unsent history from the prior
  generation permits both renewal cycles; changed history is refused.
- `adopted-complimentary-test.php`: first adopted complimentary renewal uses the
  shared union; an unverified current revision cannot reuse complimentary authority.
- The existing Eloquent Model upgrade hash also covers the Domain save boundary;
  its contract names attachment savepoints and checks after public model callbacks.

Registrar balance coverage (`registrar-balances-test.php`) uses fake readers and renders
its Filament page. It checks the earlier known renewal due/expiry date, missing dates and
costs, overdue unsent renewals, issued obligations through future-only stops, cancelled
invoices and lifecycle exclusions, exact shortfall boundaries, account isolation, current
permissions, refresh cooldown and daily wire spacing.
See [Registrar balances](../design/registrar-balances.md) for storage and forecast rules.

### Failed primary observations

`observation-records-test.php` covers ordinary complete nonempty DNS/SRV/DS inventories, known child hosts
and saved nameservers under current identity, without overflow or an existing hold. Malformed and transport
failures on the first primary read preserve the exact inventory, watermark and successful fact timestamps;
separate current failure/hold evidence stops work and blocks stale DNS/SRV forms and POSTs, including
between staff resume and successful record confirmation. A primary failure under an existing partial-record
hold also preserves evidence and blocks the previously healthy family. Healthy-primary partial-record
refreshes still save healthy facts and explicit complete/unknown families. The older 51-host overflow case
alone did not establish ordinary all-failed retention. Fixtures use synthetic transports and native SQLite.

Management deadline coverage uses synthetic transports and native SQLite. Entry claim waits
and slow preparation share the 270-second background finish cap; interactive dispatch,
privacy billing and explicit reconciliation use a 250-second entry budget. Tests cover exact
25-second headroom, subsecond refusal before seal, blocked late physical reads, retained
privacy response IDs and unchanged native finance. No provider or concurrency acceptance.

Renewal deadline coverage reuses the monotonic entry scope before capture waits. Both complete
admission passes inherit entry+270 and the stricter 250-second and quote limits; seal/consume
requires a full 25-second wire. The same matrix covers checkout, original adoption and later
completed-renewal anchors, late physical reads, response retention and unchanged native finance.
Completion capture waits consume its entry+250 read budget; original adoption proof is retained.

### Unpaid acquisition retirement and stock

`native-stock-test.php` covers idempotent legacy stock normalization, finite save refusal,
unaffected hosting products, native increment/decrement without throwing, withdrawal without
prices and stale-cart checkout refusal. `acquisition-retirement-test.php` exercises the actual
native old-Pending cron and shared hosting invoice bulk update, missing-event sweep recovery,
after-commit/rollback wake timing, fresh reservation after retirement and retained intent/audit.
Partial/processing/failed/reversed money, sent/claimed/present-null evidence, provider identity
and sibling operations retain the name and require review. It also checks old Pending financial
review, late-money non-reopening, unpaid immediate cancellation normalization, staff permission,
stale/replayed review and reason checks, plus real Filament success/refusal modals. SQLite event
schedules do not establish InnoDB concurrency or installed supplied-site-shell acceptance.
`registration-nameservers-test.php` also checks refund-once and terminal recovery for the
write-acceptance pre-seal hold, including present-null execution refusal.

Retirement coverage also exercises the actual native immediate-cancellation listener on a shared
invoice, configured cancellation delays, incomplete service-only cancellation alerts and their
resolution after proven retirement, and redirect to a fresh view without the retired action.

Nested Enom transfer-order evidence alongside a documented management refusal stays unknown.
`management-refusal-test.php` covers the parser and a non-billable lock action with inconclusive
readback: uncertain, uncompleted, sealed, blocking and never replayed. The clean refusal contrast
still closes as rejected, and sent paid refusals retain financial uncertainty without credit.
`transfer-dispatch-test.php` adds agreeing nested/top-level transfer IDs to the existing matrix
and checks that transfer references never become billing order IDs. All transports are synthetic.

### Paired acquisition and seller-switch schedules

`paired-driver-test.php` drives real native checkout and payments with the production
registration/transfer bridges and closed fake transports. It covers Enom-only then both
holders, one original provider send after changing seller, exact operation/holding/finance
retention across repeated switches, losing canonical register/transfer checkouts and cart
admission refusal, and independent per-account retail for an expensive non-selling holder.
`namesilo-only-holder-test.php` exercises both acquisition actions with only NameSilo holdings.

`seller-switch-lifecycle-test.php` covers stale carts after switch-back, two pre-existing
competing register/transfer carts with one canonical winner, original unpaid orders funded
while another account sells, unlocked fresh-read changes, a committed seal before switching,
lost provider replies and repeated pending/unknown worker delivery. The after-admission native
event asserts the lock-order guard refuses a nested seller change and rolls back checkout; these are deterministic SQLite schedules, not simultaneous
InnoDB transaction acceptance. Unknown outcomes retain exact evidence and their reservation,
with no credit, account fallback or second send.

`seller-switch-renewal-test.php` and `seller-switch-namesilo-renewal-test.php` exercise both
non-default holders through paid and complimentary renewal cycles, exact and exceeded caps,
repeated seller/no-seller changes, retained native finance and confirmed annual expiry advances.
The NameSilo case resolves a lost complimentary response through original authenticated fake
history, without replay. No unified retail policy exists: each holder uses its own account table.

Reusable entry points for adopted-holding consumer scenarios are in the bootstrap-free
`Support/lifecycle-scenarios.php`: `scenarioSwitch`, `scenarioMoney`, `scenarioAuthority`,
`scenarioRetained`, `scenarioFund` and `scenarioExecution`. Install the current provenance/adoption schema in the
calling native fixture. `Support/paired-driver.php` composes the checkout acquisition fixture;
its `$scenarioNew`, `$scenarioCompose` and `$scenarioSends` closures are checkout-specific.
Do not use that acquisition authority for an adopted holding.

The [release runbook](../release-runbook.md) coordinates installed ZIP, converter/profile/evidence,
supplied-shell acceptance, native-expiry cleanup, rollback boundaries and held/unknown support.
Provider acceptance, shared-database races and installed-shell checks remain separate release
gates. The User-deletion/checkout gap is closed; the separate adoption qualification gates still apply.

`tests/operation-claim-lifecycle-test.php` covers initial lifecycle claim and seal refusals alongside `tests/operation-claims-test.php`. Each command boots a fresh in-memory native database.

Transfer input coverage is split between `tests/transfer-inputs-test.php` (code submission, cutoff and refunds), `tests/transfer-input-evidence-test.php` (payment evidence, authority and timezones), and `tests/transfer-input-contacts-test.php` (contact correction and late readmission). Each starts with an unrelated service cancellation to check that refusal stays scoped to the affected service. Late contact corrections assert both the held state and staff-review code; replayed payment acceptance and a stale `RunOperation` job preserve that hold without queueing, provider preflight or commands.
