# Enom TEST renewal composition

Set `domains.enom_identity.environment` to `test`, `domains.enom_catalogue.enabled`
and `domains.enom_writes.renewal` to boolean `true` before extension boot. Restart
web and queue workers after changing deployment configuration. Registration has
its own opt-in. Live or unset identity environments refuse TEST renewal. This
configuration supplies no processing, catalogue or financial approval.

Use the existing positively verified TEST account and accepted annual holder
renewal terms. In Registrars, **Review TEST renewal acceptance** records an
independent check of Extend, the TEST route, request interval and USD balance.
The encrypted review binds to the immutable account fingerprint and exact protocol.
Clearing it stops new writes; retained attempts still reconcile while the
deployment opt-in stays on. Turning off the deployment opt-in pauses confirmation. It never permits resend or automatic credit.

Renewal billing and owner requests remain independent native server settings:
`domains_billing_enabled` and `domains_manual_renewals_enabled`. Staff set them in
Registrars → **Review renewal billing** (see below); neither acceptance nor
processing sets them.
A manual owner request creates or retrieves the native invoice for the current
cycle. Scheduled billing uses the saved issue/due calendar. Native payment funds
one immutable obligation; stopping future renewals preserves already-issued debt.

The worker independently checks accepted annual renewal support, current native
funding/authority, provider ownership/expiry, ordinary state, nonpremium annual
price, auto-renew off and sufficient USD balance. Unlike registration's cached
balance prerequisite, renewal's USD attestation is followed by both fresh balance
reads inside admission. Grace and redemption remain unavailable.

One ordinary renewal performs two complete six-read assessments:
GetDomainInfo, GetExtendInfo, Check, GetDomainInfo, GetRenew,
GetBalance. Then one Extend spends TEST account funds. It uses one
year and UseCreditCard=no. A daily call unit is reserved before sealing, bound to
account, operation and UTC day, consumed once before transport and never refunded.
A refusal before the seal holds the unattempted paid obligation with
`enom_test_renewal_call_cap`; an audited Release unsent hold restores eligibility
for a fresh assessment. Partial read-cap refusal takes the same safe path.

Captured-order confirmation takes five reads: GetDomainInfo, GetRenew,
GetDomainInfo, GetDomainExp, GetOrderDetail. Completion
requires unique actual charge and owned expiry advanced by the full year.
A lost reply stays uncertain. Only explicitly accepted covering renewal history
allows recovery: the four domain/expiry reads, GetOrderList and one GetOrderDetail per
bounded candidate. Zero or competing charges stay unresolved. These reads spend
call units, never renewal funds. Page views, acceptance, invoice creation, payment,
staff release/credit/replacement and duplicate completed worker turns make zero
provider requests.

`test_installed_package_enom_renewal.py` uploads the verified ZIP without source
runtime autoload, uses native checkout/payment, and resumes a pending renewal in a
separate PHP process. Two overlapping installed processes also compete for cycle
issuance and admission before the seal, proving one obligation and one Extend. It covers default-off and strict gates, fingerprint review,
calendar boundaries, duplicate callback/worker turns, future stop, cap at read five
and before the write, release and exactly one Extend, paid unsent credit/replacement,
price cap, expiry drift, auto-renew, grace refusal, negative charge/expiry evidence,
lost reply, zero/duplicate/unique accepted history, page silence and redaction.
`enom-test-renewal-guards-test.php` covers explicit-runtime default-off parity,
annual-only admission, revoked/drifted acceptance and one-use/day-bound reservation.

All responses are socket-free synthetic XML through the reviewed protocol objects.
They establish installed composition, not real provider renewal acceptance,
production database locking or live authority. The overlapping workers use SQLite. Real TEST Extend and confirmation still
need an explicitly authorized funded provider run. No provider call was made here.

Offline shape regressions use synthetic values with captured flat GetExtendInfo fields
and Check v2 envelope/empty-currency facts. The money and native admission suites
require an ordinary one-year USD 8.95 renewal; wrapped replies stay unknown.
Expiry reconciliation accepts the primary flat expiration only with matching authenticated
local-day evidence and continues refusing secondary expiry aliases. Hold, maximum,
price and currency failures retain their refusal/unknown behavior. Mutation checks cover
wrapper reads, primary-expiration refusal and empty Check currency acceptance.

Captured-read audit: `enom-read-test.php` loads synthetic full-envelope replies for
all captured read commands, pinning ownership, contact roles, nullable flags,
expiry, grouped available balance, catalogue capabilities, Check v2 and DNS shapes.
The captured WPPS dollar price is normalized only in the privacy reader; malformed
amounts stay unknown. Empty expired inventory never proves subject eligibility.

## 2026-10-22 — Scheduled renewal Auto Pay

`renewal-autopay-test.php` uses a synthetic saved-method gateway through native
`ExtensionHelper::charge`: committed once-only attempts, scheduler/existing-invoice
re-fetches, manual-invoice exclusion, full-credit settlement, insufficient-credit
retention, current partial-payment remainder, decline notification, thrown charges retained
as unknown without customer notification, interrupted outcome persistence, immutable audit
evidence and staff result facts.
It refuses missing/foreign agreements, missing/disabled gateways, changed methods,
cancelled services, pending cancellation, Renewal Off, currency drift and settled debt.
`renewal-autopay-admission-test.php` proves automatic and interactive native payment
reach the same paid-invoice funding and synthetic registrar admission state.
`renewal-autopay-adopted-test.php` retains a committed adopted Renewal Off intent
with a saved method and proves no new debt or charge. Existing renewal/refund/staff
release suites cover retained collection audit through those paths.

Collection evidence is not a registrar attempt or payment grant. An uncertain charge
is never retried automatically; native invoice transactions remain the funding proof.
These offline SQLite fixtures establish sequential/interleaved behavior, not real
gateway delivery, SMTP delivery or paired shared-database concurrency.

## 2026-10-22 — Auto Pay collection outcomes

The renewal Auto Pay tests now separate a definite gateway decline from an unknown
response. A false return requests the native payment-failed notification. An exception
records an unknown result, logs a warning without customer data, and sends no
payment-failed notification. Neither outcome retries automatically.

Uncertain, unknown and submitted collection block unpaid retirement and replacement.
The tests retain cancelled debt from the earlier implementation and refuse a new
invoice while collection is unresolved. A definite decline permits retirement and a
manual replacement. Late payment still funds the original invoice. Registrar admission
checks native paid funding separately from the saved-method collection result.

Gateway acceptance records a write-once submission. Staff facts combine that result
with the current invoice status through processing, failed payment, cancellation and
late success. Tests also switch to a second valid same-owner agreement before collection,
exercise repeated settlement, and complete a renewal before billing the next cycle.
These tests use synthetic gateways and offline SQLite; live delivery and shared-database
concurrency remain unverified.

## Staff renewal billing and owner requests

Registrars → **Review renewal billing** sets both switches and the issue/due calendar through an audited staff review
(`renewal-billing-settings-test.php`). Calendar changes apply to invoices issued afterwards;
`renewal-billing-test.php` confirms an issued renewal invoice and its operation stay unchanged.

`owner-renewal-test.php` covers **Renew now** on an active native domain page. With owner
requests off there is no form and a direct POST refuses with plain copy and creates no debt.
With them on, terms run from 1 year up to the ten-year registry horizon, and the form is offered
until the day before the confirmed expiry date. Malformed and out-of-horizon terms, foreign owners, replayed
or mismatched form keys and paused processing create nothing. A request redirects to the invoice
for the requested term; a duplicate returns the same invoice, a different term refuses, and the
page then links the issued invoice. On the expiry date the form and payment link are hidden and requests refuse.
Owner copy never shows internal reasons. No registrar job is queued.

With auto-renew off (renewal stopped), billing refuses manual requests, so the page offers no
form and a direct POST refuses with generic copy and creates nothing; turning it on restores
the form. Once a cycle has an invoice that is neither payable nor replaceable, the page shows
one status instead of the form: payment received, under review, or invoice cancelled (a direct
POST then gets the cancelled copy). After staff retire the unpaid invoice, the form returns and
a request issues a replacement invoice; payment history on the retired invoice keeps it in review.
TLD minimum and maximum years are not applied to renewals: billing and the form both cap terms
only by the ten-year registry horizon.


## Owner renewal eligibility and retained cycles

`owner-renewal-test.php` checks the page and POST against the same billing eligibility.
Processing off, paused spending, unresolved anchors, unresolved prior operations, nonstandard
pricing and inactive holdings hide new requests without creating debt. Pending, paid, cancelled
and held cycles remain visible when manual requests or renewal are off, a service is suspended,
a domain needs review, the request cutoff has passed or the saved calendar is invalid. A retired
unpaid cycle still shows its cancelled status when a replacement cannot be requested.

Payment links and retained due dates use billing's existing-invoice validation. Raw database
fixtures cover missing items, extra invoice members, wrong currency, amount, dates, owner and
operation bindings. Each shows review without a payment link or new debt; guarded model edits
are not used to create these inconsistent retained rows.

`adoption-customer-test.php` creates real renewal invoices. It checks the adopted owner page
and POST after unpaid retirement and after a paid, unsent renewal is refunded and approved for
replacement. Both offer the permitted request, return the replacement invoice and preserve the
old evidence. Duplicate submits return the same invoice and show one current-cycle panel.
These offline SQLite checks do not establish production locking or provider acceptance.

## 2026-10-22 — Owner auto-renew preference

Owners can turn local automatic renewal billing off and on. Missing
`renewal_policy.owner_auto_renew` means On. Owner Off skips scheduled invoices and
Auto Pay (`owner_auto_renew_off`), removes unissued renewals from the forecast, and
sends expiry information without promising a scheduled invoice. Issued invoices,
their payment links and reminders remain available before expiry; registrar funding rules retain their authority.
Manual renewal and payment leave the preference Off. Native cancellation, staff
stops and original adopted do-not-renew still prevent new manual renewal requests.
This supersedes the earlier statement that every renewal-off state blocks manual
requests. Staff can apply a hard stop while the owner's preference is Off.

`owner-auto-renew-test.php` covers legacy defaults, locked owner checks, intent keys,
confirmation, replay refusal, idempotent writes, encrypted staff-visible audit,
transaction rollback, corrupt audit rendering, manual payment and completion,
and billing after another annual cycle. The Auto Pay, reminders, balance forecast
and adopted customer tests cover the related stop decisions and reason precedence.
All 31 guard mutation variants fail a focused test. The original files are restored.

Turning On before the issue date waits for the saved billing window. Inside the
window, the next eligible billing run issues one invoice.
The owner switch changes no registrar
setting. These offline SQLite tests do not establish shared-database concurrency,
real gateway collection, registrar acceptance or delivered mail.


| Calendar date | Scheduled billing | New manual request |
| --- | --- | --- |
| Before issue date | Wait | Existing horizon-bounded terms |
| Issue date through due date minus one day | One invoice per cycle when On | Existing horizon-bounded terms |
| Due date through expiry minus one day | No new scheduled invoice | Existing horizon-bounded terms; due today |
| Expiry date or later | No new invoice | Refused; retained payment link hidden |

The owner-Off notice offers Renew now until expiry minus one day, then asks the owner to contact support.
The existing worker cancels provably unsent, unpaid renewal invoices at expiry, including manual invoices and owner-Off holdings; transactions or uncertain collection prevent cancellation.

`runtime-worker-test.php` checks both registrars: only the current pending renewal invoice line shows its payment due date. Paid registration, paid or cancelled renewal, and another pending cycle with the same due date hide it.
