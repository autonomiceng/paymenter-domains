# Verification

Run `python3 scripts/bootstrap.py --download` once, then `scripts/test.py` fully offline.
PHP **8.4.27**, Debian `8.4.27-1~deb13u1`, Linux x86_64 is pinned by archive SHA-256.
PHP archive SHA-256 hashes were computed from Debian security downloads on 2026-10-22.
Native runs also load gd and bcmath. ICU remains pinned at `76.1-4` with its existing hash;
its archive was downloaded again and verified without changing the pin.
Native GD requires the host `libgd3` package (`libgd.so.3`) and its dependencies; these are
not bundled in the pinned PHP archives. Native bootstrap verifies GD loads and retains the
loader error on failure. Provider-only bootstrap does not load or require GD.
Every run verifies and re-extracts archives; missing/corrupt cache fails without downloading.
`PHP_RUNTIME_ARCHIVE_CACHE` copies verified archives from another cache without changing it.
Python 3, dpkg-deb and compatible system libraries are required; no system PHP or Composer runs.

## Suites and receipts

`tests/suites.json` registers provider, contracts, native, UI, runner and package categories.
All `*-test.php` and `test_*.py` paths must be registered exactly once and exist.
Detected `*-test.cjs` files are refused until a JavaScript runner is implemented.
Lint includes every runtime/test PHP file. Unknown, missing or explicit unimplemented suites fail.
Default runs execute all implemented categories except native when no dependency is supplied;
empty categories are reported as unimplemented. `--suite` selects categories; receipts disclose
omissions. No UI suite exists yet. Synthetic provider tests do not establish provider acceptance.

Clean environment and PHP settings disable URL streams, cURL execution, sockets, process launch
and mail. Exception arguments are hidden by default; redaction cases explicitly enable them.
Synthetic namespaced cURL functions inspect configuration without sockets. These are runtime
guards, not an OS sandbox. SQLite is the only loaded PDO driver in native runs; no database sockets.
Failure, timeout (60 seconds), or changed runtime bytes stops the run. Timeout termination
kills the command process group, including nested children, before cleaning up the fixture. Private
`/tmp/domains-tests-*/receipt.json` records command exits, partial outputs, hashes, omitted and
unimplemented categories. Setup failures also leave a receipt; final dependency guard failures preserve command results. Laravel suites must explicitly
mark completion because framework exception rendering can otherwise return status zero.

## Offline native fixture

Provide an existing pristine development checkout with vendor, not an installed application:

```sh
PHP_RUNTIME_ARCHIVE_CACHE=/path/to/verified/php-archives \
PAYMENTER_TEST_APP=/canonical/path/to/paymenter-1.5.9 scripts/test.py
```

Native archives may be seeded with `python3 scripts/bootstrap.py --native`; downloads still
require the separate `--download` flag. The dependency guard pins Paymenter v1.5.9 commit
`ee729f7d99ac18942625b79f3f93d8d8b21255e8` and lock SHA-256
`fd6bdeb5557b3af0976d5688d92081abc6f7c3235806a6c4b77f4b8bca9186be`.
It checks every upstream tracked byte against that commit, rejects .env/cached config/symlinks,
and checks installed package names/versions/dist references against the lock. The sole permitted
source edit is the exact app.version development→1.5.9 substitution; the disposable export uses
original upstream bytes. Vendor byte fingerprints prove source immutability during the run,
not independent archive authentication of every dependency. No source dependency is written.

The runner copies source and vendor to a private disposable fixture, uses synthetic environment
values, migrates an in-memory database, blocks stray HTTP, and removes the copy afterward.
Native coverage includes upstream schema/model/transaction/HTTP guards, manually invoked Domains
foundation migrations and standalone retained-schema verification, global reservation/rollback,
and synthetic account/site/credential binding guards. There is no Domains entry point, billing,
production registrar identity verifier or installed extension. These are fixture-only checks.
SQLite alone cannot establish MariaDB locking, concurrent checkout or financial correctness.
CI uses cached pinned PHP and runs standalone categories; native checks need an explicitly supplied
local dependency and are not claimed by CI. No dependency checkout/download/bootstrap is automatic.

## Packaging and limits

Package support is adapted from Enom `d2c55e2` scripts/build.py and synthetic package tests.
Synthetic entry points exercise deterministic ZIP/member SHA manifests, license equality,
canonical output, no overwrite, private-file/path and symlink refusal. The actual foundation
**cannot build a release ZIP**; final native upload/install/reinstall evidence is deferred.
Runner/native fixtures adapt that revision's scripts/test.py, tests/test_runner.py,
tests/paymenter-test-app.php and tests/enom-native-app.php, preserving MIT notices.

Passing offline tests never establish real writes, sandbox acceptance, transfer completion,
SMTP delivery, browser accessibility, installed ZIP compatibility or production pricing.
See [API gates](design/parity.md). Provider/environment acceptance requires separate authority.

Enom protocol tests (slice 10) require DOM in standalone/provider runs as well as native runs.
The already pinned XML archive is now part of the base offline runtime; `php-test` loads DOM
without loading the other native fixture extensions. Missing archives still fail without download.
Its archive hash was computed from the Debian security download described above.

Enom named read normalization is exercised by `tests/enom-read-test.php` with synthetic XML and
a synthetic accepted-binding factory. Price absence differs from failed reads; ownership, expiry
precision, unknown flags, registry requirements and contact redaction have no provider dependency.

NameSilo slice 13 uses `namesilo-read-test.php` for bound deadline reads, explicit annual account
price acceptance, null premium, date-only expiry and bounded unresolved history candidates.
`namesilo-pricing-test.php` exercises the real accepted-binding and quote→rate→snapshot path in
native SQLite; failed quotes retain price revisions/freshness. Fixtures are synthetic only.

Enom slice 12 adds `enom-money-test.php` for exact source quote/renewal/order-charge evidence
and `enom-pricing-test.php` for its real CatalogueRate → PriceSnapshot persistence, failed-read
retention and below-cost renewal behavior. All transport is synthetic; no provider calls occur.

Slice 14 `catalogue-sync-test.php` exercises both real read adapters through shared acceptance,
import and seller eligibility: encrypted revisions/revocation, one NameSilo batch, compound suffixes,
live term narrowing, positive no-price, omission/failure retention and non-selling holder renewals.
All wire transports are synthetic; native SQLite does not establish provider or MariaDB acceptance.

Slice 15 `native-catalogue-test.php` covers permanent native identities, account-specific products,
exact-term projections, annual holding plans, withdrawals and malformed mapping refusals.
Synthetic SQLite publication is not MariaDB concurrency or installed hook acceptance.

Slice 15 also refuses incoming/outgoing native upgrade and config-option links, tests withdrawn-plan
sort order, and discovers product IDs before primary-key locking to avoid a broad native products scan.
The exact staged slice is tested separately before its signed commit; subsequent slice work is excluded.

Slice 16's `cart-admission-test.php` invokes vanilla Livewire Cart checkout in the disposable fixture:
shared hosting/domain invoices, distinct names on one separated product, positive below-cost coupons,
zero net, stale/seller-switched/direct-link checks, receipt tampering, malformed input and explicit
premium=false. Native tokenizer is enabled from the already pinned common package for Blade/Livewire.
Source map: Enom `444f31c` CartOrdering fields/admit → CartAdmission + CheckoutEligibility;
native mixed-cart/coupon/quantity/source scenarios → cart-admission-test. Transfer eligibility/RDAP,
contacts/registry and installed extension entry remain 27/18/39; these tests register internal hooks only.

Slice17 native invoice-binding-test exercises real mixed/multi-year checkout, synchronous immutable
ledger creation, encrypted checkout/estimated-cost facts, same-product annual holder retail, retained
coupon usage accounting and null expiry. Repeated item events are read-only; a second-line exception or global cross-account
name conflict rolls back all invoice/service/ledger/stock writes. Full-invoice funding excludes
pending/failed payments, nets succeeded reversals, refuses paid-status-only/partial funds, changed
native routes/owners/references and expired transaction contexts. Synthetic SQLite evidence does
not establish MariaDB paired races or provider/installed-engine acceptance (19/20+/39 gates remain).

Slice 18 `contact-setup-test.php` adapts Enom `444f31c` registration-setup and contact recovery
scenarios: native four-role collection, Enom/NameSilo field and phone differences, frozen encrypted
invoice snapshots, paid-only audited correction, owner/account profile isolation and retained schema.
Recorded-null execution, seals, sends, ambiguous evidence and NameSilo 301/302 purchased discrepancies
cannot become unsent recovery/refund. Contacts close without credit; `.us/.ca/.eu`, unknown/nonempty
registry schemas and arbitrary attributes remain disabled. Fixtures make no provider calls. Profile
creation/association/readback (20/30), transfer admission (27), routes (33) and installation (39) remain open.

Slice19 `operation-claims-test.php` and `operation-recovery-test.php` exercise the real native checkout/funding/claim/seal path with
synthetic preflight and writer objects: before/after-seal failures, stale workers, one-use dispatch,
reversals and changed owner/generation/reservation/processing/payload/cancellation, deadline/capability
refusals and old-seller dispatch. Cost above retail remains allowed; unknown outcomes retain names.
No provider bridge or network is installed. SQLite establishes sequential behavior only; MariaDB
races, installed queues and action-specific completion/reconciliation remain later acceptance gates.

Slice18/19 integration adds operation-contacts-test.php: actual frozen contact validation before
claim and seal, postcommit paid holds without attempts/grants, audited release, finance evidence
refusing recollection/nonbillable closure, both model guards and immutable contact policy audit.
Finance fixtures now collect real native owner contacts at checkout. All provider work is synthetic;
SQLite does not establish MariaDB interleavings or installed queue/provider acceptance.

Slice20 write-bridge/boundary/preflight tests use the real checkout/financial seal plus synthetic
named registration ports. Enom `444f31c` request gates, frozen-holder dispatch, unknown-send and
post-read financial scenarios map to disabled gates, one-use grants, exact costs, three-read budgets,
pre/post-seal evidence and owner/funding/reservation/generation/account/acceptance changes and recorded-null uncertainty.
Fresh evidence requires explicit nonpremium availability, matching term, USD cost/balance and exact
sufficient funds (including 18-digit amounts). No current-seller or retail floor applies at dispatch.
No production registration transport exists; provider contactAdd/readback, identity/currency/batch
acceptance, MariaDB races and installed queues remain blocked pending later acceptance.

Slice21 registration-enom/namesilo tests compose actual RegistrationPreflight, account gates,
financial seal/consume and bounded injected protocol ports. Enom 444f31c default-DNS purchase,
NS follow-up/readback, lost ancillary responses and incomplete/pending purchases map to retained acceptance
or uncertainty. NameSilo covers fresh owner/account/payload profile creation/non-default readback,
300/301/302, auto-renew removal/readback, stale/default/mismatched proof and no replay/refund.
Sibling protected-method attacks must make zero port calls; policy identity and real preflight
are required for protocol composition. All exchanges are synthetic; no network sender is installed.
Authority changes and a transient completion failure retain original acceptance/correction evidence;
missing/different/closure-only NameSilo profile composition refuses before claim with zero requests.

Slice22 tests use real provider read adapters with injected offline transports and native funding locks.
Enom frozen `444f31c` reconciliation-registration maps to exact captured order/domain/term/status/charge,
dedicated expiry, purchase-expiry discrepancies, unique completion and idempotent repeated reconciliation.
Provider-disposition maps to cancelled services, reversed payments, changed generation/evidence,
expired/late/missing/null executions, unresolved alerts and definitely-unsent-only terminal encoding.
Automatic-refresh maps to five-poll bounds and observation without billing-anchor changes until proof;
shared observation jobs/rate limits remain31/39. NameSilo covers no-ID native completion, exact vocabulary,
completeness/pagination, zero/multiple/credited/unknown-status rows, lag, retention, timezone/DST and budget
exhaustion. All provider facts are synthetic; these suites establish no live history/identity acceptance,
MariaDB interleavings, installed UI/queue, renewal/refund/transfer execution or notification delivery.

`reconciliation-recovery-test` exercises the slice21 residual with both same-result persistence attempts failing: provider-only unique recovery, zero/multiple candidates unresolved, and exactly one original send.

Slice23 `renewal-admission-test` composes native checkout, slice22 completion and real Enom reads over
an injected transport. Frozen Enom `444f31c` renewal-admission scenarios cover ordinary/exact expiry
boundary/grace/restore, exact multi-year costs, below-cost alerts without a floor, premium/unknown
cost, exact/unknown balance, current owner/ID, auto-renew off, disabled seller flags, account rotation,
paused spending, stale generation/revision/expiry and changed authority during reads. Tests assert
bounded reads outside locks and no invoice/operation/native-expiry mutation. NameSilo's no-ID completed
holder still refuses its unknown restoration-safe path; no synthetic Active/date inference enables it.
Paid/unpaid/complimentary funding and caps remain25, invoice/calendar24 and reminders38; SQLite
fixtures do not establish MariaDB races, installed runtime composition or live account acceptance39.


Slice23b `renewal-namesilo-test` extends native no-ID reconciliation with ordinary admission,
superseding slice23's blanket NameSilo refusal described above. It covers matching actual charges,
changed-charge refusal and prevention of admission-proof backfill, as well as
two-day UTC margin/offset/midnight boundaries, exact Active vocabulary, missing quotes, budget,
nonpremium local proof/immutability, auto-renew/balance and post-read authority checks. Reads are
synthetic, bounded and outside transactions; admission changes no invoice, operation or native expiry.
Enom ordinary/grace/restore regressions remain in `renewal-admission-test`.

Slice24 renewal-billing/native-renewal-guard tests adapt Enom444f31c (DomainBilling revision b561b9f):
confirmed21/7 calendar, native null-reference invoices, fixed multi-year obligations, repeated billing,
manual/scheduled gates, old-seller retail, below-cost/unknown-margin alerts, native paid/cron expiry,
immutable issued debt and actual Filament recalc/persistence methods under an explicit transaction. Tax, plan-null/coupon, configs,
annual mapping, zero-price and stale-price/lifecycle scenarios run offline against Paymenter1.5.9.
Provider renewal25, refunds26, reminders38 and installed39/MariaDB races remain unproved.

Slice25 adds split offline renewal execution, safety, cycles, completion and NameSilo execution suites.
They cover real native payment/credit routing, late/preference/stale obligations, retained unpaid
replacement keys, audited complimentary fixed terms/caps, final provider rechecks, one-attempt
writes and unique charge/expiry completion across repeated anchors. Provider ports are injected
synthetic exchanges; no registrar network or installed scheduler acceptance is claimed.

Slice26 adds refunds, refund-evidence and refund-renewals native suites: fresh staff rights,
account-credit once/retry, atomic credit/marker rollback, reentrant refusal, exact/full signed
funding, mixed invoices, contact holds, null/presence refusals, real typed25 prewire outcomes,
unsuitable fixed renewal terms and late retired payments. Source: Enom444f31c/d1392a8 Refunds.
These are offline SQLite checks; paired MariaDB concurrency and installed native hooks remain39.

Slice27 transfer-inputs-test covers native transfer checkout, owner POST-only encrypted code,
fixed payment cutoff/recovery, contact isolation and transfer-specific26 timeout credit/retry.
transfer-rdap-test ports frozen444f31c/d2c55e2 bootstrap/domain404, narrow TLS-close,
lock-day/calendar/status/unsafe-endpoint scenarios. All exchanges are synthetic; no transfer
write, HTTP route/UI, scheduler, provider/MariaDB or installed-runtime acceptance is claimed.

Slice28 adds transfer-status, transfer-dispatch and transfer-completion suites: both
bounded one-use transfer protocols, repeated provider/RDAP admission, explicit premium
proof, fixed input/funding gates, status contradictions, unique original charge plus
full added-year/ownership proof, native funding races, transfer renewal anchors and
unsent-only credit. NameSilo terminal vocabulary stays unresolved under the current
public contract. Exchanges are injected; no provider or installed acceptance is claimed.

Slice29 adds synthetic `management-test.php`, `management-records-test.php` and
`management-privacy-test.php`: engine authorization/one-use dispatch/readback, Enom DNS/SRV/DS/glue,
and native paid privacy plus definitely-unsent refunds. Frozen Enom444f31c is provenance.
All exchanges are injected; no provider or installed-extension acceptance is claimed.

`management-refusal-test` covers documented/system/contradictory provider refusals and the binding no-sent-credit boundary.

Native offline suites have a 120-second per-process deadline on shared, nice/ionice-limited hosts.
Installed-package lifecycle commands have a 300-second outer deadline because they run multiple
individually bounded PHP phases. Their 45- or 120-second phase deadlines remain in force. The
60-second summary above applies to other categories. Provider request deadlines remain independent.

NameSilo management: `management-namesilo-test`, `management-namesilo-contacts-test` and
`management-namesilo-boundary-test` cover free capabilities, bounded routes/readback, separate
one-write profile staging/association, uncertain recovery, frozen preparation and paid-acquisition
correction authority. All inputs and transport responses are synthetic; installed/provider acceptance remains open.

`management-preparation-test` preserves next-day Enom paid privacy dispatch targets and refuses
wrong-action enrichment or changes to frozen renewal obligations.

Slice31 observation-budget/observation/observation-namesilo/observation-catalogue suites use real read adapters
with synthetic transports to cover durable wire quotas/route caps, old holders, stale-read
rejection, pending windows, restart/lease/resume, correction links and unchanged authority.
Regressions cover local quota deferral/reset, exhausted-pending acquisition phases, reversed
management execution order and durable catalogue failure holds without extending prices.
No installed schedule, provider acceptance or concurrent MariaDB guarantee is established.

### Dormant owner pages (33)

`owned-pages-test.php` exercises current native/ledger ownership, capability labels,
held contacts, pending transfers, safe correction states and real Blade rendering.
`PAYMENTER_TEST_APP=<pinned source> python3 scripts/render-owned.py SHELL_DIR OUTPUT_DIR`
renders synthetic owner DTOs inside the supplied site shell. It needs cached native PHP
archives (`PHP_RUNTIME_ARCHIVE_CACHE`, or seed with `python3 scripts/bootstrap.py --native --download`)
and reads `SHELL_DIR/ref-domains-register.html`. It writes pages to `OUTPUT_DIR` and copies
them back into `SHELL_DIR` as `ui33-*.html` (`ui34-*.html` with `records`).
It does not boot the extension, read registrar state or enable processing.
The owner action/management suites cover exact encrypted code bytes, paid UTC cutoffs,
immutable setup, purchase grammar, native CSRF, atomic replay, engine requests and synthetic readback.
They also verify unresolved-peer/cooldown affordances and the explicit Enom default-DNS reset;
empty nameservers never imply a reset, and NameSilo does not offer one.

### Dormant staff views (35)

`staff-domains-test.php` uses pinned native Filament list, view and activity components:
16 permission combinations, deferred filters, current-role revocation, foreign binding exclusion,
unknown evidence, exact nested costs and HTML/Livewire secret boundaries. The optional
`staff-domains-render.php OUTPUT_DIRECTORY` renders static native panel fixtures with synthetic
records and the pinned compiled Filament stylesheet. Browser captures cover 1440/1024, light/dark.
`staff-domain-page-render.php OUTPUT_DIRECTORY` captures the Domains list and the domain page when active,
sync failed, demo paused under Enom Test mode, missing expiry, under review and pending order.
No provider requests, staff writes, installed registration or production acceptance are established.
The staff fixture also records Paymenter's native `*` role semantics (all read permissions,
no mutation actions) and paid-rejection credit-review selection; that SQL queue includes already
credited rows because funding is encrypted. Each row distinguishes credit markers from candidates.
Adding the extension entry and a server row in slice 39 activates native admin discovery, even for a disabled
row: permission registration and policy binding must ship with that installed activation.

### Owner record controls

`owner-records-test.php` and `owner-record-errors-test.php` exercise dormant native controllers and real Blade for Enom DNS/SRV, DS and child nameservers. They cover queued grants/readback, preimages, exact TXT bytes through native trimming, retained server-side SRV IDs, bounded public-record retry responses, field scoping, replay, current ownership, unsupported tools and 50-record limits. Private request fields are neither rendered nor flashed. Contact and nameserver inputs share the engine limits and common input partials.

`render-owned.py SHELL_DIR OUTPUT_DIR records` adds synthetic record/error/held/max-record and shared regression fixtures to the existing owner renderer. Paths are CLI arguments; the supplied site shell and assets remain external. Reference: MIT Enom `444f31ce0bfd07216e5200544bffe863119d0e7d`, DNS/glue partials, DomainController record paths, shared styles/script and glue/single-DS regressions.

These are presentation checks, not installed provider acceptance. DNS/SRV editing is unreachable without an initial complete current-generation record readback producer. A plan amendment is requested for a new observation row, proposed 31R, to own bounded durable record refresh with current authority binding; installation alone does not supply it. DNS tools remain opt-in. Glue shows only confirmed changes made here, not a complete provider inventory; unknown DS lists are explicit while additions retain fresh engine preflight. Routes, factories and writes stay dormant until installation; shared session/cache locks, bounded observation history, GET lock contention, provider registry behavior and theme navigation still require their acceptance gates.

### Dormant staff operation review

The native staff-operation suites cover independent default-deny actions, locked fresh authorization,
required reasons for money actions, immutable before/after audit, one-use keys, stale/binding refusals, exact unsent
account credits, bounded read resumes and explicit refunded-renewal replacement. Paid sent/unknown
outcomes keep their account spending holds and cannot be released or credited by these actions.
`tests/staff-operations-render.php OUTPUT_DIRECTORY` renders the native queue, views, the four
confirmations and reason refusal with synthetic data; capture at 1440/1024 in light/dark.
`tests/staff-request-page-render.php OUTPUT_DIRECTORY` captures Registrar requests and the request page
when not sent, pending, under review, unconfirmed after payment, completed, and cancelled and refunded.
No resource or permission registration is installed by these fixtures.

### Staff TLD pricing and setup (slice37)

Native Filament list, term-price detail and two-registrar setup use saved facts only.
Independent view/cost permissions, unknown/below-cost labels, stale refresh, foreign binding
and HTML/Livewire credential secrecy are checked offline. Native action tests cover reason/confirmation,
stale/replayed keys, current permissions, encrypted audit, margin holds, seller/holder separation,
acceptance narrowing/recovery and fake-only credential/probe paths. Default setup performs no I/O.
`tests/staff-tld-render.php` emits native fixtures to its output argument. No panel registration is installed.
`tests/staff-tld-pricing-render.php OUTPUT_DIRECTORY` captures TLDs & pricing and the TLD page when prices are synced,
when sync failed with its stored cause, and with no registrar for new orders, across two registrar accounts (one demo).
Source layout/labels adapt Enom `444f31c` TldResource and EnomSetup (MIT).

### Neutral reminders and obligation alerts

`reminders-test.php`, `reminder-transfer-test.php` and `reminder-safety-test.php`
use native mail/queue fakes, exact invoice evidence and both registrar accounts.
They cover retained customization, unknown/missing/held alerts, below-cost invoices,
UTC catch-up, fair bounded cursors, paid-code deadlines, real account credit,
preferences and uncertain handoff without automatic replay. No provider transport.
`PAYMENTER_TEST_APP=<pinned source> python3 scripts/render-reminders.py OUTPUT_DIR`
renders all customer template branches as native HTML/text and the changed staff
surfaces with the pinned mail shell and compiled Filament CSS. Output is external.

### Initial managed-record observation (31R)

`observation-records-test.php` composes the real Enom read seam with injected transport,
account-fair work, encrypted complete/unknown inventories, sequence ordering, stale identity
refusal and owner Blade/POST gates. GET advice uses bounded history without row locks.
NameSilo advanced families remain unknown; glue is partial confirmed local history.
No provider calls, installed schedule or production database acceptance is established.

Render complete, unknown, partial, stale-generation and many-record owner pages offline with
`scripts/render-observations.py SHELL_DIR OUTPUT_DIR`, using the pinned native fixture.

### Native runtime registration

`runtime-install-test.php`, `runtime-presenter-test.php`, `runtime-notices-test.php`, `runtime-worker-test.php`, `runtime-wire-registration-test.php`, `runtime-wire-renewal-test.php`, `runtime-wire-management-test.php` and `runtime-lifecycle-test.php` cover retained lifecycle verification, singleton hooks, activation refusal/audit, legacy exclusion, paired owner-scoped theme DTOs, mandatory notices with schedules off, encrypted audit rotation and no-resend notification review. They use pinned native SQLite fixtures and fakes only. `runtime-render.php` accepts an output directory and inert supplied-shell HTML as CLI arguments for installed native panel and customer captures. This is not provider, MariaDB or delivered-mail acceptance. See [runtime composition](runtime.md).

## Opt-in NameSilo OTE contract test

Run only with separate authority for the sandbox account and (for `--write`) its funds.
The runner is excluded from `tests/suites.json`, default suites and CI. Prepare the pinned
PHP archives offline and supply the key through `NAMESILO_SANDBOX_API_KEY` in the environment;
never pass it as an argument. No credential file is loaded by the runner.

```sh
# Offline guards; with PAYMENTER_TEST_APP this also exercises the actual registration path.
PHP_RUNTIME_ARCHIVE_CACHE=/path/to/verified/php-archives \
PAYMENTER_TEST_APP=/canonical/path/to/paymenter-1.5.9 scripts/ote-contract.sh --self-test
# Check the UTC clock before each authorized run.
date -u
scripts/ote-contract.sh --read
date -u
PAYMENTER_TEST_APP=/canonical/path/to/paymenter-1.5.9 scripts/ote-contract.sh --write
```

Both live flags refuse 18:00–21:00 UTC, with another check before every request and after
spacing waits. The sender permits only HTTPS `ote.namesilo.com`, exact Sandbox `/api` or
`/apibatch` origins, no redirects, proxies or retries, at most 60 sequential requests with
at least 1 second between starts. Purchases are limited to one generated random .com,
one registration year and at most one renewal year; explicit `auto_renew=0` and registration
`private=0` are enforced. Nameserver changes use NS1/NS2.NAMESILO.COM; DNS work is one TXT.
No caller-provided name, URL or command is accepted by the CLI.

`--read` checks balance, .com/.net prices and availability, bounded domain/order inventory
and the OTE `/apibatch/getAccountBalance` route. `--write` repeats reads before composing
the real registration preflight, sealed engine grant, profile proof, purchase mapping,
parser and adapter inside a disposable native SQLite fixture. Local identity, funding and
catalogue policy are explicitly synthetic; actual preflight reads remain real and missing
premium/contact/quote proof holds the write. The fixture never adopts another account domain.
A durably accepted production registration is followed by owned readback, bounded order/charge correlation,
NS, lock/unlock, repeated auto-renewal removal and TXT add/list/delete with readback.
Since the public renewal reference omits `auto_renew`, the write phase probes exactly one
annual renewal with that parameter only after active owned, future-expiry, auto-renew-off
readback. Request acceptance cannot establish whether an undocumented parameter was honored.
A blocked production gate is recorded; the runner does not fabricate proof to force a write.

A private `$TMPDIR/domains-ote-*/receipt.json` records request attempts before sending, shapes,
code values, parser/fixture mismatches and boolean evidence checks. Captures replace the query
key with `[redacted]`, omit all scalar account/contact/order/DNS values and unknown field names,
and assert credential variants are absent. Child output/errors are discarded; raw replies remain
in memory only. Receipts are never fixtures or committed. No account identifiers or domain names
are stored. Possibly sent purchases exit 3 and print `purchase_unresolved: true`; investigate before
another authorized write run. Exit 0 means observation completed; inspect mismatches separately.

This proves only the observed OTE contract. It does not enable production processing or establish
account identity, full history coverage/timezone/lag/retention, exact-term/premium guarantees,
restore safety, MariaDB races, installed integration or causal renewal-flag support. The real
reconciliation adapter is exercised without inventing history acceptance; incomplete evidence stays
unresolved. Production behavior changes belong in separate reviewed slices.

Contact-profile diagnostics: `--profile-read --max-requests 7` repeats the six read probes,
then reads contact inventory without creating a profile or attempting a purchase. It needs the
same pinned native fixture as write mode to reconstruct the synthetic frozen contact. Its private
receipt contains only closed field-presence/type/exact-match booleans, shape/default categories
and current claim/binding checks. Inventory is diagnostic only, never creation or ownership proof.
Write receipts separately name the first failed readback predicate, current authority checks,
retained proof and a closed hold category. Production still requires fresh creation/readback.

The profile probe now records `exact_match` separately from the documented US/CA hyphenated-phone
equivalence and reports `profile_read_gate` only for one valid non-default identity match.
The contact readback contract test covers singleton/list projection, missing identity, changed
phone digits, malformed formatting, and unknown metadata without retaining live values.

Single-name registration availability has a separately correlated read-only probe in `--read`
(seven requests), with ordinary-name and exact registration-price/balance shape checks for that
probe name. This is not funding authority for the separate write, which keeps its own fresh
preflight and sequence reserves. The offline native OTE fixture reproduces the observed `available.domain` row object, including numeric metadata.
Nested-wrapper acceptance is limited to one requested registration name; malformed/missing evidence
cannot grant purchase permission. Existing list and unavailable/invalid contracts remain covered.

### Lost-attempt OTE reconciliation

`./scripts/ote-contract.sh --reconcile --attempt-receipt /private/prior/receipt.json --max-requests 15`
performs one bounded read-only investigation of a redacted unresolved registration receipt.
It permits only listOrders/orderDetails/listDomains/getDomainInfo at the guarded transport;
it sends listOrders, one inventory page and budget-bounded details for literal two-minute-window
headers. No domain is selected from inventory. Provider timestamps have no accepted timezone;
literal-window and internally consistent synthetic registration/amount counts are diagnostics only.
The original random name and authorized amount were not retained in the old redacted receipt.
Zero, one or multiple candidates therefore remain `ambiguous`, including empty inventory or history;
exit 3 preserves the original unknown purchase. There is no write/resume path in this mode.
The normal time, credential, host, receipt and no-retry guards apply; use an explicit cap of 2–15.
`ote-reconciliation-test.php` runs only synthetic transports in the offline contracts suite.

### Retained OTE purchase evidence

New writes persist `attempt-private.json` with mode 0600 before purchase dispatch. It contains
only the generated name, exact authorized decimal amount, term, route and UTC dispatch time.
It is separate from the redacted receipt; never copy it into logs, fixtures or commits. A renewal
archives the initial file privately as `registration-attempt-private.json` before replacing it.
Unknown registration replies invoke the real driver reconciliation in the same process, using
that original file and the remaining request budget. The closed observation is `registered_in_account`,
`not_registered` (exact account absence, empty successful orders and available nonpremium name),
or `ambiguous`. Matching account ownership requires a unique exact-name/term/amount order before
sandbox sequence continuation. These observations never complete native financial operations or
accept production history completeness/timezone. Only `registered_in_account` continues the sequence.
Both `not_registered` and `ambiguous` stop with exit 3 and never retry.
A separately authorized diagnostic `--write --registration-context interactive --max-requests N`
selects the existing interactive API route inside the runner only; background remains the default
for registration and renewal. A separately authorized renewal continuation may use `/api` as described
below. Unknown renewals reconcile read-only in the same invocation before the sequence can continue.
`ote-purchase-evidence-test.php` uses synthetic transports only and is part of contracts.

### Purchase-only OTE diagnostic routes

Separately authorized `--registration-context interactive` now reroutes **registerDomain only**;
RegistrationProfiles contactAdd/contactList and all other calls retain their production routes.
`--renewal-context interactive` similarly changes only renewDomain, and requires the continuation below. Defaults stay background.
Every code other than 300 records only its code and closed category in the receipt; raw string
detail is retained solely in the private mode-0600 attempt file, including pre-purchase refusals.
Credential-bearing detail is refused. Archived attempts and private details remain receipt-redacted.

Unknown renewal replies reconcile read-only in the same invocation, including transport failures:
exact owned-name expiry versus the retained pre-renewal date plus one year, and bounded order
history/details for the attempt window. Exactly one category is retained: renewed, not_renewed
(unchanged expiry and no matching order), or ambiguous. Renewed needs a unique exact-term/amount
order and full-year expiry; uncertainty is never promoted to a successful acknowledgement.
These sandbox observations do not accept production timezone/history completeness or settle charges.

Only after a separately approved batch renewal reconciles not_renewed, one explicit continuation
may use `--renewal-only --renewal-context interactive --attempt-receipt /private/prior/receipt.json
--max-requests N` (N at least 8, within the remaining total grant). It requires matching private
registration/renewal evidence and a recent dispatch, claims the prior evidence once before reads,
rechecks the original expiry, permits no contacts/registration/management, and renews the same name
once. Failure or ambiguity stops. The initial registration phase must not be repeated to continue.
The synthetic contracts and opt-in native self-test cover purchase-only routing, private refusals,
renewal categories, failed/partial reads, exact order correlation and consumed continuation proof.

The private snapshot now also names the purchase command and renewal expiry baseline; closed
renewal category and non-success details are private metadata. Updates preserve original evidence
with synced mode-0600 temporary files and atomic replacement. Continuation checks the retained
request count plus its explicit limit against 60, and the transport requires its claimed /api route.

## Native initial records

`runtime-records-test.php` exercises the registered observation seam with accepted synthetic ports, processing pauses, daily cadence, held manual recovery, numeric DNS/SRV presentation and source-ID preservation. Staff record holds use allowlisted current-identity reasons. Customer unknown-state notes distinguish ordinary checks from manually resumed checks. All reads stay offline; native renders accept output and supplied-shell paths as arguments.

Stopped scheduled-read coverage includes a domain-read failure while processing is paused, re-enabling without automatic retry, current-phase/binding exclusion and audited manual recovery. Staff alerts verify the exact resume-action label, bound request link and missing-request guidance.

## Supported PHP

Runtime source targets PHP 8.3 and 8.4, matching Paymenter 1.5.9. The existing offline
runner retains its hash-pinned PHP 8.4.27 runtime. A separate CI job parses every shipped
PHP file on PHP 8.3 and runs `tests/php83-compatibility-test.php`.
The token guard also runs with offline lint and the registered runner suite. It rejects
40 PHP 8.4 global functions from PHP NEWS (including Intl, OPcache, PCNTL, PgSQL and
Sodium additions), asymmetric visibility, property hooks and unparenthesized
`new` dereferences, with synthetic accepted/rejected fixtures. Namespaced user functions
and imports are resolved without executing source. This targeted check is not exhaustive:
dynamic function names, extension availability and untested runtime behavior remain
outside it. Passing it does not establish installed Paymenter or provider acceptance.

Storefront catalogue parity exercises real public endings/checks with separate malformed native
price/setup-fee, altered-marker and duplicate marker-set products. Healthy and registration-only
endings remain usable; stale, below-cost and unknown-cost acquisition evidence never leaks.

Cold public catalogue coverage compares one and ten terms across distinct selected price tables,
checks every amount against strict offer resolution, and bounds ending/check query growth.
Fresh-call invalidation covers native stock/plans, TLD and seller flags/revisions, rules, account
identity, acceptance revisions and current-price proof. Query logging forbids display writes;
only explicit synthetic availability checks read the provider port.

Holding provenance tests pair purchased holdings with pending conversions: billing, renewal proof, reminders, direct dispatch, observation and owner/staff presentation refuse pending authority. The additive migration retains existing rows without fabricating purchase provenance.

Slice 41: `tests/conversion-test.php` and `tests/conversion-guards-test.php` exercise exact synthetic legacy schemas, offline accepted evidence, staged ID-map recovery, native financial/evidence retention, pending exclusions, configuration/queue/maintenance/backup refusals and replay safety. The one-time development converter is excluded from the runtime archive; see `scripts/conversion/README.md` for its guarded operator workflow. Real application databases and registrar transports are never used by these suites.

PSL updater tests use small synthetic ICANN/PRIVATE lists and injected clocks/transports.
`psl-test.php` covers retained copies, validation refusals, conditional requests, shared daily admission,
weekly eligibility and exact 30/90-day boundaries. `psl-transport-test.php` uses namespaced cURL
fakes to verify public-address pinning, HTTPS, no redirects/retries, deadlines and response caps.
No list download, discovery/resolver or provider acceptance is established.

`psl-staff-test.php` mounts the installed native page, submits source/update actions,
revokes permissions after mount, checks all age states and exercises weekly runtime work
without accounts. It runs the registered scheduler callback with a frozen clock: fresh installs
queue on the next minute, recent and failed attempts queue nothing over 1,440 minutes,
weekly and daily network boundaries remain exact, and a missing status table queues nothing. Native fixture defaults inject a failing PSL transport so incidental ticks
can never resolve DNS or download data.

### Runtime archive verification

`python3 scripts/build.py --output-dir /fresh/output` builds the complete native runtime.
References reachable from the entry point and native Admin discovery, plus literal
boot/view/migration paths, determine included roots. Source README files and tooling stay out.
Sorted uncompressed entries use fixed metadata, avoiding compression-toolchain variance. The runtime conversion
completion guard remains included; the one-time converter and its directories do not.
The embedded `Domains/manifest.json` hashes all payload files. The adjacent release
`manifest.json` hashes every ZIP member, including the embedded inventory, and the ZIP itself.
Run `python3 scripts/build.py --verify /fresh/output/paymenter-domains-0.1.0.zip` before upload.
Checksums detect corruption, not publisher identity; obtain artifacts from a trusted source.
Package tests build the real tree twice, verify every member, omit referenced roots and
refuse converter paths, tampering, unsafe names, symlinks, collisions and oversized files.

### Native archive lifecycle acceptance

The native suite verifies the built ZIP before passing an upload copy to Paymenter
1.5.9's real UploadExtensionService. A second disposable app copy uses installed files
without the source-tree runtime autoloader. Native ZIP support uses a pinned PHP archive
and requires the system libzip library; missing offline dependencies fail rather than simulate.
Keep git object `e7881cf404e934e1cc84931ab9036e84804b8b61` available for the
previous local development-build upgrade (same 0.1.0 version, before the additive PSL schema).
Fresh-process boot checks migration history, installed classes/pages/routes and one scheduler.
Same-ZIP reinstall, previous-build upgrade and file-removal/reinstall via native Install preserve
raw ledgers/settings and all prior migration records; only account update timestamps are
normalized because native pause touches them. Native server deletion calls disabled then
refuses retained accounts; the separate uninstall hook also retains data. Upload-time schemas
are verified before repeating the native upgrade command as an idempotency check.
Customized notification templates and populated synthetic PSL state are retained too.
Converter injection and a modified embedded manifest are rejected before native upload.

### Default-held registration from installed bytes

The installed-package fixture starts a third fresh native Install and proves no catalogue
ending/offer, no admitted native cart line and no checkout order/service/invoice/operation.
An adversarial native cart row with positive stock/price must receive the specific neutral
checkout refusal and roll back. Identity verification and audited catalogue review leave
processing off without accepted runtime composition and shared workers. A backfilled queued
registration cannot reach a provider executor; the real registration writer refuses with
`write_disabled` before engine resolution or wire calls. Transfer and renewal feature gates
remain independent and default off. No provider requests or live acceptance are implied.

Registrable-name tests use only synthetic PSL fixtures: ICANN longest matches, wildcard/exception,
PRIVATE holds, ASCII A-labels, U-label holds, invalid hostnames and revision changes.
PSL rebaseline tests bind acceptance to pending bytes, fresh staff rights, typed short hash and reason;
current validation remains mandatory and acceptance retains encrypted append-only audit evidence.

Watch storage tests use installed native SQLite fixtures. They cover disabled revisioned mappings, fresh/default-deny permissions, customer/name and service uniqueness, exact target invariants, immutable owner identities, encrypted evidence/audit, source retirement with retained service identity, lifecycle retention and missing migration history. No discovery runtime, public lookup or browser evidence is implied.

Worker-deadline regressions in `operation-recovery-test.php` cover the 245-second last-send
boundary, refusal beyond it and claim-lock waits that consume the same entry budget. Late contact
recovery in `Support/late-contact-matrix.php` commits and explicitly retains the staff review hold
without adding queued jobs. Synthetic clocks and SQLite do not establish production lock latency.

Operation clock regressions cover elapsed fall-back boundaries and native datetime round trips. Operation rate regressions cover unchanged preclaim states, the durable 60-second retry, one eventual send and staff release of disabled writes.


## Opt-in MariaDB conversion rehearsal

The required suites always omit `mariadb`. Run it explicitly through the local suite limiter:
set `TMPDIR` to a private scratch root, `PAYMENTER_TEST_APP` to the pristine pinned 1.5.9
checkout, `CONVERSION_ENOM_CHECKOUT` to a local checkout containing both accepted Git
revisions, and `CONVERSION_MARIADB_PHP` to a supported host PHP with `pdo_mysql`; then run
`python3 scripts/test.py --suite mariadb`. No installed application or provider is used.
Docker or the existing `mariadb:11.4.5` image being absent produces a clear skip receipt;
there is no image pull. A skip exits 77, records incomplete acceptance in both receipts,
and is never MariaDB evidence. Docker context/environment variables are forwarded only for
this explicit suite. Acceptance requires two completed pin runs and no skipped status. A pre-existing dedicated container name refuses rather than being
reused or stopped. The harness owns only `dom-c41r-mariadb`, with no network, published ports
or persistent volumes, and stops it after success or failure.

Each exact source revision runs actual native and source migrations, purchased/pending,
payable/history refusals and queue audit scenarios, actual app-profile schema/dry/apply
commands with real quiet windows, financial/encrypted/reminder readback and database/files
restore. Its private receipt records command elapsed times, full re-inspections, image ID,
source revisions, SQL invariants and unchanged pristine dependency. This proves sequential
conversion on the pinned MariaDB image; it grants no production cutover, concurrent-lock,
provider or distributed scheduling acceptance. Linux user, mount, PID and network namespaces
plus a native inspectable crontab command are required. The fixture mounts an empty private
cron spool inside its own mount namespace and inventories its own isolated processes; no host
cron spool is changed. This proves the disposable CLI only, not shared-host process/cron readiness. The converter runbook contains host prerequisites.

After an ungraceful crash, use the private `container.json` Docker endpoint and inspect only
`dom-c41r-mariadb`; stop its immutable ID only when its `domains-conversion-rehearsal` label
matches that file, otherwise leave it alone. Never clean up by name alone.
`registration-nameservers-test.php` exercises both registrars through the real registration
preflight and native claim/finish path with a synthetic writer, independently of the bridge's
earlier owner-setup guard. Empty hosts, excessive counts and invalid hostname labels hold
unsealed as `nameservers_required`, with zero reads/writes and a specific staff label. Enom
privacy refusal keeps precedence; valid inputs still seal and reach the synthetic writer.
The existing registration protocol suites cover valid purchases and follow-up readback.
Claimed nameserver holds also retain definitely-unsent rejection and exact once-only account credit;
unknown execution evidence still blocks both recovery proofs. Both registrars exercise staff
refund and terminal-rejection recovery paths.

Registration reconciliation covers captured execution with a null order reference: a saved,
covering history acceptance permits Enom history reads and unique completion without replay.
Terminal-proof checks assert held/sealed/expired lifecycle reasons before exercising refusal
and re-read persisted reservations afterwards. Their example placeholders use the operation
fixture's sole published suffix, `.com` (`Support/commerce.php`).

## Coverage notes

Coverage details are in the [testing coverage index](testing-coverage.md), with area files in
`testing-coverage/`. Add new notes to the matching area file; this file keeps setup and suites.

## Parallel offline suites

`python3 scripts/test.py --jobs 4` uses four workers. `--jobs 1` keeps serial execution.
`DOMAINS_TEST_JOBS` sets the default; otherwise it is half the CPU count, between one and four.
Each worker owns its fixture, PHP runtime and temporary paths. Source and copied dependencies are checked before commands start and after the run.
Console output prints each command's block as it completes; receipts keep manifest order and indexed output files.
Receipts add `jobs` and command `seconds` for concurrency and timing.
Installed-package commands (`test_installed_*.py`, including NameSilo enrollment) run alone.
The pool drains before each one and resumes after its integrity check finishes.
Command `started_seconds` and `finished_seconds` are monotonic offsets from run start;
the finish includes the integrity check, so receipts can verify that commands did not overlap.
The first failure stops new work; running commands finish. Runs that include MariaDB run serially.
In parallel runs, writes to `Domains/`, even when restored, fail.

Public registry expiry tests use synthetic bootstrap/HTTP bodies, endpoint DNS and clocks.
They retain date/instant precision, reject ambiguous expiry and unsafe endpoints, and
share the transfer client's strict selected-endpoint 404 classifier. No WHOIS fallback,
registrar operation, live RDAP acceptance or customer notification is exercised.

Registry work tests cover default-off/profile-independent enrollment, owner and native stamp
rechecks, exact claims, crash recovery, independent retry/circuit/429 policies and urgent
freshness transitions. RDAP and DNS reservations precede wire attempts; local deferral preserves
fresh facts. Delegation tests cover TTL-spaced classification confirmation and overflow cohorts.

Watch scheduling tests use synthetic local discovery and dispatch callbacks, an injected clock
and an asynchronous queue configuration. They cover five-minute turns, bounded product/owner
rotation, duplicate hints, queue timeouts, outage deduplication, and 90-day evidence retention.
Activation and live/concurrent resolver/queue acceptance remain separate operator gates.

Watch notice policy is exercised offline by `watch-notice-plan-test.php`: exact UTC windows,
nearest discovery, bounded catch-up, retained correction keys, clock reversal, independent DNS
confirmation and restoration only for previously handed-off channels. Pure inputs grant no
owner, installed queue, public resolver or SMTP acceptance.

Public notice delivery coverage uses independent native mail/app fakes, retained per-channel claims,
opt-out and owner/role/retirement checks, daily priority, render failures, and managed relink proof.
The owner preference page uses native web/auth, CSRF and one-use session keys with stale revisions refused.

Domain summaries use batched native/ledger owner checks and local observation display stamps.
Coverage checks source/property/order/profile changes, partial empty-label refusal, safe hosting
links, constant query counts and no display writes. Public reads remain synthetic and offline.
Managed cases also cover adopted generations, retired work, the expiry-day boundary and retained
last-known values. Current badges require fresh evidence; historical values carry per-axis stale flags.

The domain portal suite covers the registered/related section order, neutral unknown labels,
read-only paused/retired detail, fresh preference eligibility, owner-safe hosting rows,
local GETs and query-free extension Blade. The exact native service view adapter is pinned
by the upgrade tripwire; it composes one named view and changes no native component state.

The hosting Domain row uses an exact `services.show` view composer because native
service fields have no documented append hook. It only changes view data, fails
closed to an omitted row, and never mutates the native component. The test-only
tripwire pins its template/component dependencies; upgrading requires rechecking
this narrow adapter when those files change.

Staff watch coverage uses native Filament pages, fresh permission checks and actor-bound one-use revision tickets.
It exercises stale/revoked actions, local queue wakeup limits, retained leases, disabled configuration saves,
separate reviewed enablement, archived source links, schema drift and read-only pending adoption.

Staff watch summaries batch up to 250 watches across owners using the same local projection
as customer summaries. `staff-watch-summary-owners-test` compares every DTO field against
per-owner batches, checks constant queries for 25 rows across 1/5/25 customers, and injects
foreign-owner DNS/registry/source/native identities to verify isolation. Fresh watch-view
permission is required. The attention filter evaluates the first 1,000 active names in four
bounded batches; the cap bounds local decryption and response work, with no provider reads.

### Focused iteration and timing evidence

The PR boundary still runs `python3 scripts/test.py` with the pristine native dependency
and pinned runtime cache configured. Use `--only` for exact registered test paths during
focused iteration, for example:

```sh
python3 scripts/test.py --only tests/test_runner.py tests/test_native.py
python3 scripts/test.py --only tests/test_installed_package_previous.py
```

The second command requires `PAYMENTER_TEST_APP`, just like the native suite. Selection
keeps manifest order, deduplicates paths and never adds implicit lint. Unknown paths,
invalid manifests and mixing `--only` with `--suite` fail. Category selection remains
available through `--suite`. A focused pass is not full boundary acceptance: receipts
record `only_tests`, `omitted_tests` and selected/omitted categories. `complete` describes
the selected plan, including its dependency and runtime guards.

Every command, including lint, prints a JSON timing row with its command index,
execution `seconds` and integrity `guard_seconds`; indexed stdout/stderr files remain
unchanged. Receipts also record setup, command scheduling and final-guard stage durations
in `timings`. Its `run` value includes command execution and guards, excluding setup,
receipt writing and cleanup. Parallel command/guard totals are summed worker time,
not elapsed wall time. Measure full wall time around the runner separately.

`native-paid-baseline-test.php` is the single entrypoint for the shared paid-processing matrix.

`provider-send-guard-test.php` checks all three physical registrar senders with synthetic cURL,
all named command routes, UTC metadata, default-null sends and closed failure paths.
`provider-send-guard-operation-test.php` checks a funded native registration's guard refusal,
retained unsent evidence, staff release and later one-time dispatch without changing its debt.
