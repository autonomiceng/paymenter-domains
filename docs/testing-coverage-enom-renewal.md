# Enom TEST renewal composition

Set `domains.enom_identity.environment` to `test`, `domains.enom_catalogue.enabled`
and `domains.enom_writes.renewal` to boolean `true` before extension boot. Restart
web and queue workers after changing deployment configuration. Registration has
its own opt-in. Live or unset identity environments refuse TEST renewal. This
configuration supplies no processing, catalogue or financial approval.

Use the existing positively verified TEST account and accepted annual holder
renewal terms. In Registrar setup, **Review TEST renewal acceptance** records an
independent check of Extend, the TEST route, request interval and USD balance.
The encrypted review binds to the immutable account fingerprint and exact protocol.
Clearing it stops new writes; retained attempts still reconcile while the
deployment opt-in stays on. Turning off the deployment opt-in pauses confirmation. It never permits resend or automatic credit.

Renewal billing and owner requests remain independent native server settings:
`domains_billing_enabled` and `domains_manual_renewals_enabled`. There is no staff
editor for them in this release. A deployment must explicitly set the desired
boolean settings on its Domains server; neither acceptance nor processing sets them.
A manual owner request creates or retrieves the native invoice for the current
cycle. Scheduled billing uses the saved issue/due calendar. Native payment funds
one immutable obligation; stopping future renewals preserves already-issued debt.

The worker independently checks accepted annual renewal support, current native
funding/authority, provider ownership/expiry, ordinary state, nonpremium annual
price, auto-renew off and sufficient USD balance. Unlike registration's cached
balance prerequisite, renewal's USD attestation is followed by both fresh balance
reads inside admission. Grace and redemption remain unavailable.

One ordinary renewal performs two complete eight-read assessments:
GetDomainInfo, GetExtendInfo, Check, GetDomainInfo, GetRenew, GetRegLock,
GetWPPSInfo, GetBalance. Then one Extend spends TEST account funds. It uses one
year and UseCreditCard=no. A daily call unit is reserved before sealing, bound to
account, operation and UTC day, consumed once before transport and never refunded.
A refusal before the seal holds the unattempted paid obligation with
`enom_test_renewal_call_cap`; an audited Release unsent hold restores eligibility
for a fresh assessment. Partial read-cap refusal takes the same safe path.

Captured-order confirmation takes seven reads: GetDomainInfo, GetRenew,
GetRegLock, GetWPPSInfo, GetDomainInfo, GetDomainExp, GetOrderDetail. Completion
requires unique actual charge and owned expiry advanced by the full year.
A lost reply stays uncertain. Only explicitly accepted covering renewal history
allows recovery: the six domain/expiry reads, GetOrderList and one GetOrderDetail per
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
