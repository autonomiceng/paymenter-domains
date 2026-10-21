# Coverage notes

Per-feature test coverage and its limits. Setup, suites and receipts are in [testing.md](testing.md).

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

Concurrent staff User deletion and admitted checkout for that same user remain an adoption
activation blocker: deletion can pass with no routed children, then cascade a later committed
service. This also affects current admitted checkout with adoption off. Adoption stays off.
Native deletion remains available without an outer transaction;
observers do not open a transaction spanning deleting/deleted. Drain/restart alone cannot close
this current-worker race.

`native-identity-cascade-test.php` reproduces that exact gap with actual admitted checkout.
Post-delete User/Service observers inspect at most 25 retained domains and save a staff discrepancy;
the existing durable runtime domain cursor inspects at most 25 domains per account turn to recover
missed/overflow checks while the saved account binding remains current. Registrar setup lists the saved alert under Missing Paymenter records and shows it even when native detail cannot open.
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
acceptance. A0's adoption-off deletion/checkout gap and privileged-maintenance exclusions remain.

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
See [Registrar balances](design/registrar-balances.md) for storage and forecast rules.

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

The [release runbook](release-runbook.md) coordinates installed ZIP, converter/profile/evidence,
supplied-shell acceptance, native-expiry cleanup, rollback boundaries and held/unknown support.
Provider acceptance, shared-database races and installed-shell checks remain separate release
gates. This coverage does not close the documented User-deletion/checkout activation gap.

`tests/operation-claim-lifecycle-test.php` covers initial lifecycle claim and seal refusals alongside `tests/operation-claims-test.php`. Each command boots a fresh in-memory native database.

Transfer input coverage is split between `tests/transfer-inputs-test.php` (code submission, cutoff and refunds), `tests/transfer-input-evidence-test.php` (payment evidence, authority and timezones), and `tests/transfer-input-contacts-test.php` (contact correction and late readmission). Each starts with an unrelated service cancellation to check that refusal stays scoped to the affected service. Late contact corrections assert both the held state and staff-review code; replayed payment acceptance and a stale `RunOperation` job preserve that hold without queueing, provider preflight or commands.

## Native registrar server settings

`native-server-settings-test.php` disables the legacy Secure route remaps and Livewire
mount/hydrate interception through a testing-only switch. It exercises native creation
from absent extension tables, encrypted setting exposure to native server-edit staff,
unchanged/name saves, ignored undeclared credential payloads, native permissions and
compiled server-route metadata (without compiled-route dispatch). Native Edit Service without its Secure editor is not
qualified by this suite and requires the lifecycle/paid-path and removal integration. Synthetic verifiers exercise both registrar bindings,
same-account rotation, wrong-account refusal, credential/digest and copied-pair holds before reader
construction, distinct disabled-server/live-site recovery messages and cached balance hiding without erasing the row.
Scoped server/account identity/deletion guards, disable/re-enable pause and legacy Enom
exclusion remain active. Owned configuration actions retain redacted audit history; the
rendered history omits the retained account fingerprint. No provider transport runs.
See [Native registrar settings](design/native-server-settings.md) for the accepted access
boundary and the separate removal gate. SQLite and Livewire fixtures do not establish
installed UI, production database concurrency or provider acceptance.

## Staff filter lookalikes

The staff domain and operation suites apply real deferred Filament filters to simultaneous,
current-binding fixtures and check exact membership/counts. Provider-rejected transfers sit
beside code timeouts; failed transfers sit beside rejected registrations and renewals.
Frozen calendar cases cover yesterday, today, the inclusive window end and the following day,
with expired selection checked separately. Held, unresolved and selected statuses have positive
and neighboring records. Credit-review cases distinguish state, action, paid/complimentary
funding and provider-rejection reasons, including null reasons and the intentionally included
credited queue row. Existing paid-uncertainty projection parity remains independent. These
synthetic offline SQLite/Livewire checks do not establish provider or database concurrency acceptance.

`staff-tld-reads-test.php` checks the explicit renewal-off staff message with accepted current
renewal/acquisition prices, its rendered list description and overall sales-pause precedence.
Public catalogue, search and strict offers remain unavailable while renewal is off; restoring
renewal restores the healthy contrast. Exact saved price rows, revisions and freshness survive
these switch changes and reads without provider I/O. This is synthetic native SQLite coverage.

- DNS readback: equivalent IP spellings share canonical tuples and digests; provider CNAME/MX root-dot variants confirm through preflight, observation and reconciliation. Desired targets remain strict; malformed provider targets refuse, MX preference stays significant, and TXT/redirect bytes remain exact.

- Retired privacy: both paid actions cover native late payment, cancelled invoices, current staff credit, atomic failure/retry, retained claims/deferrals, queue visibility and forged/sent/changed-line or insufficient-funding refusals. Older retirement markers remain review-only.

A3 core `adoption-commit-test.php` uses the reusable lifecycle money/authority
snapshots against serialized native SQLite. It covers default-off activation,
actor/digest/permission checks, 120-second expiry and future proof, changed native
owners/order/property/quantity/expiry, credentials/site/price revision, no-charge
new-name adoption, pending promotion with retained IDs, native Order-before-Service
query order, idempotent replay without repricing and full rollback after a native
projection callback fails. All provider traffic uses closed fake transports;
confirmation makes zero provider calls. This is not concurrent database acceptance.

A3 core also renders the native holding review warning for retained billing-window
and price alerts, checks cutoff wording and excludes arbitrary provider alert text.

A3 history `adoption-consequences-test.php` covers exact paid/cancelled invoice links,
fresh invoice-view permission, pending-debt refusal, cancellation/history exceptions
and first/second save veto rollback. Real DNR confirmation retains active/null native
expiry, original history/cancellation on replay and byte-identical money. Scheduled,
manual, complimentary, reminder, funded-admission/funding, installed dispatch and
direct executor paths refuse renewal; sent outcomes remain reconcilable. A fully
funded counterfactual proves the funding refusal is the DNR boundary. Expired display
is read-only and keeps the reservation. Original cancellation deletion refuses; a
privileged missing-row fixture fails authority closed. `adoption-intent-test.php`
checks immediate conflict, existing stop reuse and cross-object grant isolation;
`adoption-renewal-intent-test.php` checks a later ordinary end-of-period owner stop.
These use serialized SQLite and closed fake transport, without concurrency acceptance.

The adoption consequences fixture renders the native holding page with retained
issue-window/cutoff flags and original renewal-off intent; no invoice warning appears.
The later-stop fixture separately preserves issued-debt reminder wording.

A3 `staff-adopt-commit-test.php` exercises native wizard selection/readback/review,
default-off confirmation, current permissions, stale refusal, actual adoption and
safe browser state. `adoption-enrollment-test.php` injects postcommit audit failure,
repairs atomically, retains NS history/work cursors, isolates owners, suppresses old
watch work and proves no network, financial change or duplicate enrollment on replay.
`adoption-customer-test.php` renders actual customer Blade with escaped company text,
original invoice route-key collisions, expiry/renewal-off and activity. Issued pending
due dates survive settings changes; paid current cycles never promise another invoice.
Offline screenshots cover native wizard 1440/1024 and customer detail 1440/1024/375,
both themes. Customer capture framing is synthetic; installed supplied-site-shell,
managed public DNS execution and delivered notices still need separate acceptance.
Unissued renewal presentation also checks the day before, at and after the billing
cutoff; dates at/after cutoff show review without promising new debt.
Local clock cases on either side of UTC midnight match the actual billing cutoff.
Existing invoice/model and native staff framework tripwires name the new consumers;
no approved dependency hash is changed.

The customer Blade fixture adopts renewal-on, issues debt and then creates a native
end-of-period cancellation; issued-debt/reminder rules remain visible.

Offline TEST captures keep the deployment flag on while live confirmation stays disabled.

The native staff label inventory covers adopted watch retirement and dormant public work.

Parity filter/registry follow-up adds simultaneous TLD rows for two Enom accounts and a NameSilo
account, all with the same ending. Individual and combined selectors assert exact inclusion,
exclusion and counts. Registry duplicate cases discriminate option order, title and description,
assert the existing safe flags/options messages and retain both empty-option fill directions
and flags-before-fill checks. Driver conflicts still yield unknown with no schema facts. These
are synthetic offline tests, not provider or installed-browser acceptance.

### Reserved fixture names and PHP 8.3 guard

`fixture-policy-test.php` checks the opt-in reserved-name helper. Rate, terminal-proof,
reconciliation and wrong-domain money/management fixtures pass their actual generated
names through it. The three commerce suites publish a synthetic `example.com` compound
ending so distinct subdomains retain normal checkout, funding and reconciliation paths.
The timestamp-boundary case retains its model/query-builder clock assertions.
Other synthetic fixtures remain outside this bounded policy check.

The PHP 8.3 scanner and planted-call matrix cover the same 40 PHP 8.4 global functions,
including optional Intl, OPcache, PCNTL, PgSQL and Sodium functions. Every name is tested
as an unqualified fallback and an uppercase fully qualified call, and as a user-defined
namespaced function. This remains a targeted static guard, not full runtime qualification.

W2D: `doh-reader-test.php` exercises strict DNS JSON/Question validation, RCODE mapping,
CNAME owner semantics, transport-only fallback, source/reason facts, per-job breaker reset,
ten shared slots, public bootstrap validation and remaining-deadline limits through injected
Guzzle handlers. `doh-settings-test.php` checks native encrypted first save/readback, invalid
configuration, stock staff permissions/revocation, form validation and bounded diagnostic states.
All exchanges are fake. `doh-render.php` renders stock native staff states for both-theme review.
Mutation checks target NXDOMAIN/SERVFAIL fallback, false native-empty proof, missing breaker,
ignored deadline and zone-content fallback. Native fallback still cannot impose a per-call timeout.

### Account observation claim index and budget refusals

`schema-test.php` pins the physical `(server_id, account_fingerprint, due_at)`
observation-work index and checks that retained verification rejects its loss even
when native migration history skips creation. Restoring it permits install/verify.
The installed-package test explicitly aligns its pinned older local fixture's
creation baseline with this unreleased index; it still tests retained native
upload/reinstall and additive migrations, not upgrade from the obsolete index set.
`observation-budget-test.php` accepts only `ReadDeferred` for durable quota and
concurrency refusals; a planted binding-change `DomainException` must escape that
assertion. Existing runtime-worker headroom/ceiling assertions already use the
specific refusal. These are synthetic offline SQLite checks; they do not establish
MariaDB query plans, concurrent locking or provider acceptance.

## Transfer dispatch command budget

Transfer dispatch scenarios run as three independent native commands: Enom sends/RDAP/order
references (`transfer-dispatch-test.php`), NameSilo sends (`transfer-dispatch-namesilo-test.php`)
and input/feature/pre-seal refusals (`transfer-dispatch-refusals-test.php`). The shared
`Support/transfer-dispatch-fixture.php` retains the original native checkout, funding and
five-mode dispatch assertions once per registrar. Splitting bounds cumulative fixture rows
without replacing native checkout or weakening replay, refund, admission or uncertainty checks.
These are offline synthetic SQLite scenarios; provider and concurrent-database acceptance remain separate.

## Managed public DNS storage

`managed-public-storage-test.php` covers the target union, separate encrypted immutable
facts, no managed watch creation, dormant defaults, retention and missing-history refusal.
`managed-public-upgrade-test.php` upgrades original 000018 enrollment rows in place,
preserving IDs, continuity/profile pointers and awaiting-acceptance state. Partial retained
upgrades refuse repair. These serialized SQLite tests make no DNS or registrar calls.

## Managed public DNS runtime

Synthetic reader tests cover dormant A3 row acceptance in place, do-not-renew holdings,
atomic predecessor relink and continuity-only pointers, after-commit hints and daily repair,
retained expired/deleted/moved-owner facts, full stamp races and exact claim replacement.
Quota tests prove the shared watch/managed sixty-query pool, two-job ceiling and local
saturation without failure. Long-TTL confirmation keeps badge freshness independent;
101-source batches and invalid/conflicting hosting sources retain independent apex reads.
Owner fairness, bounded source rotation, managed-only DTO selection and registrar/financial
isolation are exercised without network. The existing watch observer runs through the same
pool. Mutation checks reject stamp bypass, watch creation and separate quota admission.
These serialized SQLite checks do not establish live resolver or MariaDB concurrency acceptance.

## Native paid-loop qualification and closed-order payment review

The same offline matrix runs with the retained paid-invoice adapter and with a test-only
unbound native processor. It covers mixed hosting/domain/Credit, early synchronous and
serialized delayed native jobs, duplicate wakes, contact recovery, multi-day transfers,
refund interruption, re-payment/reactivation, capability loss and scheduler recovery.
Existing acquisition alerts distinguish new payment activity and expose a staff filter and
permission-aware invoice/request links. Ordinary completed cancellation and retained refunded
payments stay quiet. Publication initializes approved product mail once and preserves later
staff changes, including null and blank. Native paid-loop dependencies are test-only tripwires.

## Seller-switch lifecycle command budget

Seller-switch lifecycle runs one native command per registrar/acquisition action through
`Support/seller-switch-lifecycle.php`; the original command keeps the two native checkout
transaction guards. Each original register/transfer schedule still runs once for each
registrar: stale carts after switching back, canonical contention, unpaid funding, fresh
reads, durable seals and unknown workers. Native checkout, funding and retained-byte
assertions are unchanged. Independent processes bound accumulated commerce rows. These
synthetic SQLite checks do not establish provider or concurrent-database acceptance.

## Acquisition retirement command budget

Acquisition retirement runs as independent native commands for cron/wake-up/cancellation
(`acquisition-retirement-test.php`), uncertain money/send/sibling/age refusals
(`acquisition-retirement-refusals-test.php`) and staff review/audit/late-money/modals
(`acquisition-retirement-staff-test.php`). `Support/acquisition-retirement.php` shares
unchanged native setup. Related released-name and shared-invoice scenarios stay together;
real native checkout, cron, cancellation listener, paid activation and Filament actions
remain exercised. These synthetic SQLite checks do not establish provider or concurrent
shared-database acceptance.

## Native paid processing after override removal

Both historically named baseline/candidate entrypoints now run the same native-only matrix.
The test switch and self-rebinding are gone; composition asserts the processor is unbound
and resolves to the native class. Existing paid/cron, cancellation, identity and projection
coverage qualifies the removal together. No upstream hashes were changed.

## Native editor removal

Native server/settings, lifecycle, presenter and fresh-boot fixtures qualify unchanged
Create Server, Edit Server and Edit Service routes, real `route:cache`, and native pages
opened before a compiled-route change and hydrated afterwards. Scoped guards still
refuse unsafe identity and lifecycle writes; ordinary saves and paid hooks continue.
Native Edit Server Delete is exercised for both registrars: refusal is handled with a
staff notice, preserves settings/binding/server, and retains the native processing pause.
An unrelated native server still deletes. Direct model deletions still throw.
The upgrade tripwire pins native delete dispatch and Filament's handled Halt behavior.
The runner hygiene suite requires the conventions exceptions section to be empty.
Old Secure-class snapshots and caches require drained deployment, cache rebuild and page
reload; offline native fixtures do not establish installed-theme or provider acceptance.

## Owner retry input and list projections

The paired owner-management suites exercise normalized native POST input, allowlisted contact
and role retention, field errors in only the failed contact step, escaped redisplay and domain
scoping. Nameserver, lock and setup retries retain their nonsecret values; confirmations and
injected private fields are excluded. Owner-action tests assert code failures flash no input.
Record-error suites retain bounded public DNS/SRV/DS/glue drafts without session flashing.
Real active holdings compare full and list DTO facts and assert zero settings queries for list
projections. These offline fixtures do not establish provider or installed browser acceptance.

## Installed-package scenario commands

`test_installed_package.py` runs default-held registration and all three unsafe-archive
refusals. `test_installed_package_fresh.py` runs the current-build lifecycle;
`test_installed_package_upgrade.py` builds the pinned previous revision, applies the
existing observation-index fixture adjustment and runs the previous-to-current lifecycle.
Both lifecycle commands retain install, seed, repeated upload/verify, server-delete/verify
and file-removal/reinstall/verify phases. Each command gates on 1.5 GiB available memory
and owns a temporary application/database under the runner fixture parent.
Each prints only its scenario's JSON evidence; together they retain every original key,
including both archive hashes and previous-fixture provenance. Offline native SQLite
acceptance does not establish provider, installed-theme or concurrent-database acceptance.

## Native service refusal feedback

`native-service-refusal-test.php` and `native-service-refusal-holding-test.php` run
the real native Edit Service page with each guard boot order. Manual price Save,
each native identity field (product, plan, user, quantity, currency, coupon), and
Delete return handled danger notices with unchanged service, ledger, invoice, settings
and processing state. The Active Delete case checks the default terminate-server
branch against the real Domains no-op with native auditing enabled: only its native
terminate audit is added and the same refusal is handled. No workflow or registrar
job is queued; only the fixture's existing native checkout after-response callbacks
replay.
Recalculate Price exercises the actual hint action: the native
success notice and draft value remain, stored state stays unchanged, and Save of the
changed draft is handled as a price refusal. Hosting saves and deletion still work.

Non-UI writes keep the exact original DomainException type/message. Scoping probes
exercise an unrelated Livewire page, a different native record, unrelated mounted
actions, Save during Delete and deletion without the Delete action. Mutation probes
remove these checks independently and must fail the same acceptance matrix. Offline
rendered notifications cover price, recalculated-price Save, identity and Delete at
1440/1024 in dark/light; they do not establish installed-theme or browser interaction
acceptance. Native Active expiry validation remains an accepted upstream limitation.

Public notice planner: `watch-notice-plan-test.php` covers expiry-window and DNS-episode policy
with synthetic current/stale/revision inputs; persistence, delivery and installed acceptance remain separate.

Watch notice delivery: independent native channels, queued versus uncertain states, render-before-claim,
missing-only template installation, native and public preferences, urgent priority, owner/role changes,
retirement, managed proof/claim continuity, accepted-only restoration and stale predecessor interleaving.

Watch notice overflow: a conflicting profile outside the observed source cohort refuses fresh DNS proof and reserved mail.

## MySQL/MariaDB schema identifiers and native lifecycle

`schema-identifiers-test.php` compiles the real migration/create/alter and retained-reference
Blueprints with Laravel's MySQL grammar, without a MySQL server or PDO connection. It checks
all quoted identifiers, every verifier call site and every migrated table, with index prefixes
on/off and empty, short and maximum-fitting table prefixes. This runs in the ordinary native
suite. It caught both the long verifier reference names and the 65-character watch-observation
index; that index now has a table-derived `_facts_idx` name. Retained index names are not compared.
`schema-reference-safety-test.php` forces a reference collision and a failure after CREATE:
foreign reference rows survive the collision, while owned partial DDL is cleaned up.

`test_schema_mariadb.py` is a separate opt-in `mariadb` test: Paymenter 1.5.9's fresh native
Domains install, uninstall/reinstall, upgrade, every retained table, data/metadata retention,
and reference cleanup after success and schema drift run on `mariadb:11.4.5`, with empty and
`p` connection prefixes (one character fits the existing 63-character balance index). Upstream's invoice migration contains literal unprefixed SQL, so the
prefixed fixture migrates native tables first, renames them, then runs the Domains lifecycle. Use `TMPDIR=/private/scratch PAYMENTER_TEST_APP=/pristine/paymenter
CONVERSION_MARIADB_PHP=/path/to/php-with-pdo_mysql python3 tests/test_schema_mariadb.py`.
It uses only an existing image and its own randomly named `dom-schema-*` container, with
`--network none`, a Unix socket, a temporary data filesystem and network-isolated PHP.
The receipt records runtime and confirms container removal and pristine dependency preservation.
The measured two-case run took 45 seconds, with a 512 MiB container and a 1.5 GiB
available-memory preflight. It is omitted from default runs; no provider calls, ZIP-uploader, checkout/worker race or live
acceptance is claimed. Its cost is one container startup and two fresh native lifecycle runs.

## Customer presentation polish (2026-10-20)

`owned-pages-test.php` includes `Support/owned-tone-cases.php`: a table-driven presenter
unit matrix covers every D3 status/tone row, both adoption-pending predicates, held and
uncertain registration/transfer operations, awaiting payment, actionable setup, unknown
states, and adopted expiry before/at/after the boundary. Labels stay unchanged. Existing
real controller pages also assert the shared all-test banner copy.

`storefront-test.php` includes `Support/customer-polish-views.php`: real Blade/DOM cases
cover all/some/none test banners and per-row tags on pricing, index and search (plus empty
index), annual-only column hiding, mixed/absent/longer terms, annual mobile renewal labels,
missing renewal prices and both footnotes. Initial idle and checked AJAX results retain
test labels using only the public selected seller's environment. No private holding identity
or provider I/O is added. These offline tests do not establish installed-theme acceptance.

## Reminder failure visibility

`reminder-safety-test.php` drives the real account schedule and reminder sweep with
an injected private exception. It checks warning severity, the exact constant text,
and context limited to domain ID and exception class. A throwing logger still
permits staff failure evidence and the rest of the sweep; a healthy next holding
receives its notice and repeat selection does not resend it. Unmanaged holdings
skip before managed-authority locks without deliveries or false failure alerts.
These are synthetic native SQLite checks with fake mail and no provider calls.

## Staff UI polish (batch A)

`staff-ui-polish-test.php` renders the native Domains table and counts the single
current-acquisition query and eager customer query. It covers held/uncertain/no-open
badges, resolved/stale/foreign lookalikes, identity fallbacks, normal-text unknown
expiry and exact uncertainty wording at zero, one and several hours.
`native-identity-readiness-test.php` checks each moved site fact once, retained
account facts, runtime copy and display-only reminder sorting.
`native-server-settings-test.php` checks processing disabled before configuration
and after mismatch, enabled when matched, and pause available during a hold.
`staff-adopt-commit-test.php` checks the escaped readiness lists and customer-first
selection/confirmation. Both native service-refusal guard orders now assert Delete
unmounts with its danger notification while records and finance remain retained;
Save keeps its draft. The Cancel dependency is pinned in the upgrade tripwire.
These are offline native HTML/Livewire checks, not installed screenshot, contrast,
MariaDB concurrency or live registrar acceptance.

### W5 customer-polish restack

Customer-polish Blade cases now use DomainSummary on the index, retaining every pricing,
banner and row-tag assertion. The visible empty page is checked independently of hidden totals.
All summary registration/DNS/state tones render on both index and detail; unknown keys are muted.
Known expired/grace/redemption remains bad, while a reported expiry-day boundary is a warning.
OwnedDomain retains its D3 matrix and serves owner forms and NativePresenter theme consumers.
Real controller pages cover all/some/none owned test mixes; reserved public results retain the
selected seller test flag when a private holding account is live. These checks stay offline.

### Customer list chip density

`Support/customer-polish-views.php` pins the list rule shared by the domain index and the
"Related to your hosting" rows: state chips first, then known evidence, then one chip for all
missing axes. A freshness chip (Not checked yet, then Checks are out of date, then Checks partly
complete) covers missing axes; otherwise a single muted "Some details unavailable" chip does.
A row with three unknown axes renders exactly one muted chip. An exhaustive DNS/pointer/partial
matrix asserts the collapse never adds a label, never shows a per-axis unknown chip, and never
gives unknown or partial evidence a positive or empty-case label. Shuffled freshness states pin
the priority order. The detail view still lists every axis and keeps the "Check details and
timestamps" disclosure with its check times. Related rows keep the not-registered and no-coverage
notices out of the chips, and managed context still hides adoption chips. Eight mutations (old
order, uncollapsed related rows, gap plus freshness, kept unknown axes, invented "DNS only",
swapped freshness priority, dropped managed exclusion, dropped check times) each fail the test.
These checks are offline Blade/DOM checks.

The domain index shows "Renewal off" once, in its Renewal column: the row drops the matching
state chip only when that column says "Renewal off". Related rows, the compact summary and the
detail summary have no Renewal column and keep the chip, as does a row whose column says
something else or "Pending verification". The matrix asserts the column variant drops only that
chip. Eight mutations (duplicate chip, chip dropped everywhere, related rows claiming the column,
ignored column text, an extra dropped state, pending counted as shown, chip dropped from the
managed detail, column text removed) each fail the test.

### 2026-10-20 — staff reviews and active worker evidence

`staff-operation-lease-test.php` uses real funded claims, seals and send verification
with the existing fake clock and offline preflight. Recheck, reconciliation resume
and scheduled-read resume are refused during a live running lease, in both the
claim→seal and seal→verifySend windows. The UI hides all review actions; a stale
callback returns fixed user-safe text. Equal-to-now, past and absent leases retain
staff recheck/reconciliation recovery. A read-finished audit also refuses a lease
acquired during unlocked I/O, retaining the durable requested audit unchanged.
No claim-evidence comparison or append-only guarantee is relaxed.

`staff-reason-test.php` checks four/500/501 Unicode characters, invalid UTF-8,
control characters, actual Filament form/server behavior and safe refusal text.
Arbitrary DomainException/runtime messages remain behind the generic notification.
Settings, holdings, contact recovery, acquisition retirement, reminder schedules,
watch writes/mappings/profiles, notice resend and PSL tests cover their own Unicode
limits. Adoption already validates UTF-8 and its max-only 500-character form rule.
Watch storage's internal 1000-character limit and the PSL 1000-character rule remain
distinct from the usual staff 5–500 and resend 10–500 rules.

Staff audit removal/empty replacement changes only `staff_actions`. Management
policy removal changes only `management`; management read-history removal/empty
replacement preserves every unrelated attempt key. Temporary guard-removal
mutations must make each isolated refusal assertion fail, with the guards restored
before the full suite. These are sequential synthetic SQLite interleavings, not
shared-database concurrency, provider acceptance or production evidence.

## Transport refusals before cURL setup

`tests/enom-curl-test.php` and `tests/curl-transport-test.php` count `curl_init` as well as `curl_exec`. Every pre-dispatch refusal (invalid deadline or credentials, TLS key logging, unsafe parameters, purchase mapping) asserts that no handle was created and no request was sent.

### Staff watch findings

`staff-watch-findings-test.php` renders the native staff watch list and detail over eight
synthetic watches (healthy, confirmed and unconfirmed nameserver change, expiry reached,
expiry within 30 days, out-of-date registry, not checked, paused). Each row's findings must
equal the customer `DomainSummaries` state keys, render with the customer tones, and show
"No findings" when healthy. The native attention filter must return exactly the six actionable
rows. Customer name and email appear only with `admin.users.view`; the native user link also
needs `admin.users.update`; revocation falls back on the next request. List, filter and
detail GETs leave work, observation, registry and notice rows unchanged. Query counts are
equal for 1 and 25 rows of one customer and for a 25-row page across one or five customers.
The filter evaluates the first 1,000 active names once per request in batches of at most 250;
applying it costs one more render plus one multi-owner batch per chunk. Mutations to the attention rule, permission
checks, expiry window, tones, stopped-watch handling and the filter memo each fail this test.
Offline only; no installed or live checks.

`staff-watch-findings-test.php` also pins the watch detail header: its flex bases apply only at 40rem and wider. Below that Filament stacks the header as a column, where a flex basis becomes height and left about 800 px of blank space above Findings at 375 px.

External watch transfer comparison: `watch-transfer-test.php` checks a local help-only GET,
strict annual transfer evidence, the 90–14 day window, status/premium/unknown refusals,
owner and revision revalidation, explicit authority and the real neutral native cart.
A second unknown registry read inside cart admission must refuse the handoff. Numeric
savings require independently verified, matching annual USD/tax-exclusive renewal evidence;
no such source is supplied to the runtime UI. Transfer completion exercises a failed relink
audit, atomic rollback, local repair, retained source history and definitely-unsent notice
suppression while accepted/uncertain deliveries survive. Public workers remain dormant.

Installed watch/adoption gate: repeated same-version native upload/upgrade and reinstall
retain encrypted
registry facts, adoption previews, watch source/profile/map rows, outstanding work leases,
notice episodes, uncertain deliveries and customer opt-outs byte for byte. The fixtures
prove previews remain unattached and maps/profiles, adoption and comparison activation
stay off. Repeated native upload, refused server deletion and file removal/reinstall share
the same retained snapshot. These are SQLite/offline checks; live provider acceptance,
shared-database races and actual delivery remain separate operator gates.
Cross-version watch-table retention awaits a previous build containing those tables.

Staff TLD query coverage compares 25/100 rows and repeats each size with extra price terms, retaining fresh-grant revocation checks. Settings review coverage
measures queries and encrypted-setting retrievals with 10/500 audit archives, exercises real archive rotation, exact unchanged-stamp replay refusals and
transactional key rollback. Corrupt acceptance permits narrower terms and a sales pause while still refusing widening and restoration. Recent history is bounded
independently of retained replay keys. The historical installed-package fixture adopts the unreleased review-key/archive declaration explicitly; it tests native
uploads and additive migrations from that baseline, not conversion or automatic repair of superseded missing-table schemas.

Installed default-held package acceptance also covers runtime candidate isolation for offline display rows and missing native invoice authority. It verifies
retained reconciliation/refund selection, safe actionable warnings, shared UTC daily diagnostic slots, permission revocation and secret-free read-only
Activation status rendering from the extracted ZIP.

Staff operation diagnostics exercise the actual provider-evidence and paid-line guards and missing-invoice recheck through staff actions.
Each unexpected refusal emits exactly one warning containing only a closed reason, action and numeric operation ID; unknown failures
show a matching reference without exception text or class names. Expected staff validation stays quiet. Failed reads retain read_requested
and read_failed with a safe code, consume the review key and preserve financial/provider evidence without transport calls; successful
rechecks retain read_finished. Mutations removing the warning or recording failure as read_finished must fail these assertions.
Synthetic offline SQLite coverage only; no live provider, installed UI or shared-database acceptance is established here.
