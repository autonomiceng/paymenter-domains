# Verification

Run `python3 scripts/bootstrap.py --download` once, then `scripts/test.py` fully offline.
PHP **8.4.26**, Debian `8.4.26-1~deb13u1`, Linux x86_64 is pinned by archive SHA-256.
CLI/common/curl hashes were checked against the Debian security index on 2026-10-17.
Optional native sqlite3/mbstring/intl/xml and ICU hashes are pinned from the existing local
reference archives; their upstream index provenance has not been independently rechecked.
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
Its upstream hash provenance remains the locally pinned evidence described above.

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

Runner timeout correction: native offline suites have a 120-second per-process deadline on shared,
nice/ionice-limited hosts. The 60-second summary above applies to other categories.
Provider request deadlines remain independent.

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
renders synthetic owner DTOs inside the supplied site shell.
It does not boot the extension, read registrar state or enable processing.
The owner action/management suites cover exact encrypted code bytes, paid UTC cutoffs,
immutable setup, purchase grammar, native CSRF, atomic replay, engine requests and synthetic readback.
They also verify unresolved-peer/cooldown affordances and the explicit Enom default-DNS reset;
empty nameservers never imply a reset, and NameSilo does not offer one.
