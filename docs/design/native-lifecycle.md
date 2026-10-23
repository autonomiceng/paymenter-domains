# Native lifecycle contract: Option E

Decision: 2026-10-19. **Pure Option E:** native Active means paid native billing state.
Registration, contacts, transfer progress and registrar expiry remain Domains-owned truth.
No fifth native status, detached acquisition invoice references or owned credential store is planned.

This is the target contract, not completed cutover evidence. Source citations were checked at
Domains `810f777` (D, paths relative to this repository) and Paymenter 1.5.9
`ee729f7d99ac18942625b79f3f93d8d8b21255e8` (P, paths relative to upstream).
These are evidence revisions, never runtime version gates. This decision supersedes older
completion-tied native activation, finite-stock withdrawal and native-server-admin secrecy
requirements in [storage](storage.md) and the current [runtime description](../runtime.md).
The implementation gaps and removal gates below remain mandatory.

## Native billing and owned registrar state

Native Active does not prove a registered name, ready contacts, completed transfer or permission
to spend. A paid registration can be held and a paid transfer can remain pending for days.
Paymenter exposes four native statuses (`P/app/Admin/Resources/ServiceResource.php:82–91,202–210`).
The owned domain ledger, operation evidence and confirmed provider expiry remain authoritative;
acquisition reconciliation establishes owned active/expiry/revision/billing-anchor together
(`D/Domains/Services/OperationReconciliation.php:76–90`). Funding still requires the complete
invoice's current net succeeded payments and frozen identity, independently of native Active
(`D/Domains/Services/InvoiceFunding.php:55–65,75–100`). Claims, setup, generation and prior-outcome
checks remain required; a native status or callback grants no registrar execution authority.

Customers see the owned Domains list/detail, contact setup and transfer-code progress; native
service actions expose Manage domain and Domain status when native Active makes them available
(`D/Domains/routes/owned.php:13–17`; `D/Domains/Services/OwnedDomains.php:17–75`;
`D/Domains/Domains.php:33–36`; `P/app/Livewire/Services/Show.php:41–61`). Staff use
Domains → Domains and Registrar requests, which show owned status, confirmed expiry and request
state/reason (`D/Domains/Admin/Resources/DomainResource.php:19–23,39–46`;
`D/Domains/Admin/Resources/OperationResource.php:15–19,31–49`). Native billing labels must not
be interpreted as registrar completion.

## Paid path, wake-ups and accepted mail timing

Keep the native paid loop unchanged: `P/app/Observers/InvoiceObserver.php:45–51` runs
ProcessPaidInvoiceService after commit, then emits Paid. Its Service-referenced items call
RenewServiceService; ServiceUpgrade and Credit siblings keep their native processing
(`P/app/Services/Invoice/ProcessPaidInvoiceService.php:18–49`). Initial acquisition items keep
their Service references. Owned renewal items retain their separate null-reference billing path.
RenewServiceService queues CreateJob for Pending or UnsuspendJob for Suspended, then calculates
expiry, sets Active and saves (`P/app/Services/Service/RenewServiceService.php:18–28`).
The managed expiry observer must neutralize that calculated date before SQL.
Renewal credit follows Paymenter's global 'Use credits automatically' setting, mirroring CronJob::payInvoiceWithCredits.

Domains create/unsuspend only wake existing eligible work; suspend/terminate are no-ops
(`D/Domains/Domains.php:28–32`). Never create a new obligation, release a hold, replay a seal or
infer success from these hooks. Current service wake-ups select queued/awaiting-payment/code
operations, excluding held work (`D/Domains/Services/RuntimeWork.php:13–19`). Paid-event and
bounded scheduler recovery remain independent (`D/Domains/Services/Runtime.php:62–63`;
`D/Domains/Services/RuntimeWork.php:44–55`). Early synchronous jobs, queue failures, duplicate
Paid/manual create and late jobs after refund must all be qualified before removing the override.

CreateJob tries once and normally sends the native activation notification after the wake-up;
UnsuspendJob wakes without that notification (`P/app/Jobs/Server/CreateJob.php:21–44`;
`P/app/Jobs/Server/UnsuspendJob.php:20–38`). Its success means wake-up, not registrar success.
The native “Service activated” timing is accepted even before setup or registrar confirmation.
Recommend neutral subject/body through the admin's shared `new_server_created` template settings
(`P/database/seeders/EmailTemplateSeeder.php:143–163`); this also changes hosting wording.
No template code override or new completion mail is required. Repeated manual create may repeat
mail; scheduler recovery does not repair missed native activation mail. Owned missing-code,
expiry and account-credit notices keep their independent evidence rules.

## Null expiry and the reduced native editor

On **updating**, silently map every managed native `expires_at` write to null, staff included.
The guard also normalizes ordinary saving. Active exact-object grants are checked before route
queries and already plan a null expiry.
Exception: unsupported instance increment/decrement with non-null expiry extras is refused
and logged. Eloquent passes the original extras straight to its query builder **after** the
updating event, so changing model attributes cannot clear those SQL values. No native Service
numeric caller exists in the qualified upstream; numeric updates without expiry extras continue.
Do not replace this narrow refusal with post-SQL compensation or global interception.

Unsupported privileged installed-PHP limits: slice 2 does not authorize instance numeric price
extras, and numeric ServiceCancellation identity reassignment is outside the guarded contract.
Eloquent passes the extras to SQL after the model events fire, so those events cannot reliably
identify an arithmetic reassignment destination. Native Paymenter has no numeric Service or
ServiceCancellation caller; its numeric writes affect stock, credit, affiliate counters and rate
limiting. Cancellation creation and ordinary reassignment remain guarded. No interception is added.

The scoped saving/updating implementation is `D/Domains/Services/NativeExpiry.php`;
`NativeDomainGuard::boot` and `NativeIdentity::boot` register it before their own save guards.
Scope original and proposed native identities/routes before applying the guard; native writes
must not escape by changing an ID or parent. Validate and clear pre-existing non-null managed
dates during qualification. SQL date comparisons do not select null, excluding these services
from native invoice, dated suspension and termination queries
(`P/app/Console/Commands/CronJob.php:53–55,171–194`). Domains-owned confirmed expiry and renewal
billing therefore remain necessary; null does not itself cancel a registrar registration.

Use the native Edit Service page without Filament header-action blocking or button disabling.
Scoped model guards refuse only unsafe deletion, identity/parent/config change, unsafe paid
in-flight cancellation, or a non-engine price change; log the refusal and tell staff. Other
native saves and the ordinary paid loop continue. Provide one scoped public render-hook notice
on managed Edit Service: “This is a domain; manage it on the Domains page.” Final UI wording and
screenshots belong to its implementation slice. No global Livewire interception or route remap
is an acceptable replacement.

The native form still requires expiry for Active recurring services
(`P/app/Admin/Resources/ServiceResource.php:97–100`): blank expiry fails form validation;
a typed date can pass validation and is discarded on update. This is an accepted reduced
editing capability, not a promise of normal native lifecycle editing. Native Recalculate Price
still derives an acquisition-plan price (`P/app/Admin/Resources/ServiceResource.php:124–150`),
so use owned audited **reprice holding**, **stop/resume renewal** and **retire unpaid acquisition**
actions. Update renewal price and stop/resume renewal are implemented below. Unpaid acquisition retirement remains deferred; there is no owner-change action.
Since slice 2, every ordinary native price change requires an owned audited transition, even
when its amount equals current retail (`D/Domains/Services/NativeDomainGuard.php:130–131`).
Private exact-object projection authority selects the locked Service and planned values.
SecureEditService still supplies a transaction and modifies expiry form fields
(`D/Domains/Admin/SecureEditService.php:11–26`); its removal remains a separate gate.

## Publication, cancellation and money after closure

All managed product stock must be null, including withdrawn offers. Migrate old zero/positive
values and refuse finite-stock configuration. Withdrawal removes acquisition prices, retaining
product/plan identities; acquisition admission rechecks owned publication, seller, acceptance,
price and eligibility under locks. Current publication still sets stock zero on withdrawal
(`D/Domains/Services/NativeCatalogue.php:152–184`); the existing batch/price admission seam is
`D/Domains/Services/CartAdmission.php:75–101`. All-null stock remains unimplemented until 3b.
It prevents native cancellation increments from reviving a withdrawn offer; never throw on
native cron's stock increment or depend on later republication to repair it.

Native unpaid cron may cancel old Pending acquisitions without a paid invoice and cancel their
reachable pending invoices, including a shared hosting invoice. That native mixed-invoice policy
is accepted (`P/app/Console/Commands/CronJob.php:124–138`). Native bulk invoice cancellation does
not emit individual model events. Add after-commit wake-up plus a bounded durable sweep for
owned retirement; cancelled native status alone is not release/refund proof. Retirement requires
positively proven definitely-unsent, unfunded acquisition history and no provider/charge/claim
ambiguity. Partial, processing or late money stays for review. Current reservation release still
requires an acquisition account-credit marker (`D/Domains/Services/DomainReservations.php:30–71`);
the separate unpaid retirement proof and release path are planned in 3b.

Refuse unsafe paid in-flight ServiceCancellation creation **before** its queued side effects,
and unsafe native status changes, by locked state/proof regardless of caller. The native
immediate listener queues termination, cancels the service and pending invoices
(`P/app/Listeners/CancellationCreatedListener.php:15–30`), while acquisition funding rejects
cancellation records/cancelled services (`D/Domains/Services/InvoiceFunding.php:59–63,82–84`).
Allowing that mutation can strand reconciliation; a terminate no-op alone is insufficient.
End-of-period cancellation means **stop future renewal**, never registrar deletion: null expiry
cannot reach dated termination, and the cancellation record stops owned billing
(`D/Domains/Services/DomainBilling.php:93–99`). Preserve already-paid obligations for their
existing evidence-based disposition; stopping renewal does not authorize a refund.

Late money or re-payment can set even a cancelled native service Active unconditionally;
cancelled→Active queues no CreateJob (`P/app/Services/Service/RenewServiceService.php:18–28`).
Record a durable discrepancy for staff review, keep terminal/refunded owned state non-spendable,
and never reopen a released reservation or automatically credit excess money. Do not throw in
the ordinary paid loop and block hosting/Credit siblings. Durable terminal projection and
late-money review are still planned in 3b/4.

Refund rules are unchanged: current full signed funding, exact eligible line, definitely-unsent
negative proof, and one atomic account credit/refund marker. Acquisition credit rejects the
owned acquisition and cancels its native service (`D/Domains/Services/Refunds.php:41–73`).
Sent/possibly-sent/unknown acquisitions, fallback purchases and sent transfer rejection grant no
credit (`D/Domains/Services/RefundProof.php:9–20,40–49`;
`D/Domains/Services/OperationReconciliation.php:66–69`). No automatic gateway refund exists.

## Credentials and unsupported native features

Keep credentials in native encrypted server settings, encrypted before first save; no duplicate
store or credential migration. Verified rotation must preserve the immutable account identity
and pauses processing (`D/Domains/Services/AccountBindings.php:43–48,157–177`). Native encrypted
settings decrypt on retrieval and Edit Server fills every setting into form state
(`P/app/Events/Setting/Retrieved.php:19–26`;
`P/app/Admin/Resources/ServerResource/Pages/EditServer.php:23–29`). Native server-edit admins
therefore have registrar-secret access, the accepted native secret-key boundary. A placeholder
is not secrecy; do not claim otherwise. Retain Registrar setup permissions, redacted diagnostics,
account/site binding and verified configuration. A credential digest mismatch holds processing
until verified reconfiguration (`D/Domains/Services/AccountBindings.php:139–153`); native edits
are not identity verification. Preserve key/decryption and retained-history rotation requirements.

Native gateway subscriptions/billing-agreement enrollment for domain services, ServiceUpgrade
flows and owner changes are unsupported. Null expiry does not make subscriptions compatible
with owned renewal invoices. Native billing-method writers remain exposed
(`P/app/Livewire/Services/Show.php:65–82`;
`P/app/Admin/Resources/ServiceResource.php:153–182`); scoped public admission/write guards and
qualification must close those paths without header-action blocking. Catalogue mapping already
refuses upgrade/config links; keep forged-item/import admission checks and immutable ownership.

## Ordered replacement slices and deletion gates

These Option E slice numbers are separate from the original implementation-plan numbering.
Slice 0 records decisions only. The original/proposed identity classifier and ordered locks
in slice 1 remain a prerequisite; no completed slice-1 qualification is claimed at this base.
Keep the existing upgrade rules in [extension conventions](../paymenter-extension-conventions.md#keeping-paymenter-upgradable):
public surfaces, guards at the write, required-capability checks and test-only sha256 tripwires;
no core edits, global interception or runtime version gate.

- **2:** replace broad projection bypasses with exact-object field/value/expected-state authority
  for initial, pricing and refund writers; preserve funding/terminal checks and native status writes.
- **3a:** qualify scoped updating expiry, reduced-editor notice, unsafe mutation/cancellation
  refusals and owned audited reprice/stop/resume controls. No removal-only cleanup here.
- **3b:** migrate all stock to null; qualify publication/admission, unpaid/shared-invoice cron,
  bounded unpaid retirement/release, late-money discrepancy and durable sweep. Preserve uncertainty.
- **4:** qualify the untouched native paid path and wake-only hooks together with both 3 parts:
  mixed hosting/domain/Credit, early/sync/duplicate jobs, setup holds, long transfers, failed
  registration, refund and re-paid/late-job terminal behavior. Retain the owned renewal engine.
- **5, removal only:** delete the ProcessPaidInvoiceService binding
  (`D/Domains/Services/NativeDomainGuard.php:21`) and `D/Domains/Services/ManagedPaidInvoice.php`.
  Gate: slices 1–4 qualified together on baseline/candidate, including safe paid cancellation
  and nonthrowing ordinary paid/cron writes. Preserve completion's funding validation if its
  redundant native activation (`D/Domains/Services/InvoiceFunding.php:68–71`) is later removed;
  do not bundle that behavior change into this removal.
- **8:** qualify native Registrar setup/server-create installation, permissions, native encrypted
  settings/payload exposure and health under pure E, after 3a. No owned-storage dependency.
- **9, removal only:** after 5 and 8, delete native route remaps and registration
  (`D/Domains/Services/Runtime.php:42–48,54`), Livewire EventBus mount/hydration guards
  (`D/Domains/Services/NativeServerGuard.php:22–29`) and all three `D/Domains/Admin/Secure*`
  classes. Qualify native route caches/stale pages and health without those dependencies.
  Retain scoped lifecycle/account guards and legacy Enom writer exclusion
  (`D/Domains/Services/NativeServerGuard.php:30–49`).

All replacement slices above remain planned at this base. Owned credential-storage/migration
slices 6–7 are dropped. Global TrimStrings exceptions have already been replaced by owned raw
POST decoding (`D/Domains/routes/owned.php:7–12`); do not reintroduce them. Passing current
offline suites is not Option E cutover, provider, installed UI or database-concurrency acceptance.

## Lifecycle guard qualification and cutover

Run `php artisan domains:native-expiry` to validate retained managed native dates. A nonzero
exit means dates remain; it changes nothing. With processing paused and native workers drained,
run `php artisan domains:native-expiry --clear`, then validate again before restarting workers.
The explicit command reclassifies each locked service, logs each cleared ID, leaves hosting
and provider-confirmed dates untouched, and is idempotent. Privileged raw/quiet/bulk writes can
still reintroduce dates; repeat validation after maintenance. Installation never silently repairs data.

Cancellation protection uses a retained owned intent before an allowed unpaid native write.
This closes payment/claim admission between creating and the native INSERT. A failed native
INSERT can leave the service safely held; no timeout or resume acknowledgement clears that
intent. Paid and attempted acquisitions require original terminal proof; a succeeded/rejected
label alone is insufficient. Missing protection schema refuses only lifecycle mutation, with a
clear upgrade message. Active native paid-loop saves remain available. See
[native intent storage](native-intent-storage.md). Owned renewal controls are described below.
For converted holdings with no acquisition row, only the current billing anchor's complete
authenticated proof permits cancellation: converter-sealed history or a later original renewal
completion. A terminal label or invalid conversion MAC cannot authorize cancellation.

## Owned holding controls

Domains staff with Manage domain operations and the specific holding permission can reprice,
stop or resume one holding. Each reviewed action requires a one-line reason, checks a 15-minute
actor/domain/action snapshot under the ordered holding locks and appends encrypted before/after
evidence. A stale, replayed or revoked review refuses; 100 retained entries pause further changes
until a reviewed retention mechanism is available. No audit entry or cancellation intent is erased.

Reprice applies the current published annual renewal retail, including applicable tax, through
the exact-object projection capability. It does not publish prices or rewrite issued invoices.
Future invoices still use retail current at issuance, including the existing retained-price rules.
Stop prevents new paid and complimentary renewal obligations; issued debts remain payable and
issued paid and complimentary obligations keep their existing evidence-based execution path,
including registrar spending. Hold an unsent request before stopping to prevent that spending.
Stop controls domain-expiry renewal billing; separately requested privacy purchases and renewals
remain available. Native end-of-period rows
have the same future-only meaning. Resume may delete only reviewed end-of-period rows; immediate
cancellation and retained acquisition intent require review and cannot be cleared here. Neither
action sends a registrar request, deletes a registration or changes registrar auto-renew.

Owner change, native subscription/billing-agreement enrollment, upgrades and unpaid acquisition
retirement remain deferred. These controls do not complete the later Option E removal gates.

Native cancellation edits/deletions keep Paymenter auditing when native auditing is enabled;
owned actions additionally require their retained review reason. Disabling native auditing is
a privileged installation choice. Original sent renewal reconciliation ignores later native billing lifecycle
changes while retaining original identity and full-funding checks. An unsent paid obligation
with immediate cancellation stays held for its existing evidence-based refund/review path;
no automatic refund or registrar request is inferred. Management readback already bypasses send
eligibility while retaining its original binding checks. Existing-debt reminders continue;
month-out notices promise no new invoice while future renewals are stopped.
