# Staff and customer presentation coverage

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
