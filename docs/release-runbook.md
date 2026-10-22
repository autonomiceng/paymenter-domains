# Candidate release, rollback and support

Use this checklist with [runtime composition](runtime.md), the
[conversion procedure](../scripts/conversion/README.md) and the
[native lifecycle contract](design/native-lifecycle.md). Record the candidate commit,
archive hash, environment, operator, time and independent acceptance receipts privately.
Keep credentials, contact data, transfer codes and raw provider replies out of tickets.

## Qualification and installed candidate

1. Start from a clean reviewed commit. Run the complete offline runner described in
   [verification setup](testing.md), with its pinned native fixture. Retain `receipt.json`,
   selected/omitted suites and command counts; a partial or skipped run is not a green run.
   Run the opt-in MariaDB conversion rehearsal separately where its prerequisites exist.
   It qualifies conversion transactions and fencing, not checkout/worker concurrency.
2. Build and verify a runtime archive in a fresh output directory:

   ```sh
   python3 scripts/build.py --output-dir /fresh/output
   python3 scripts/build.py --verify /fresh/output/paymenter-domains-0.1.0.zip
   ```

   Verification checks the embedded manifest and payload. Obtain the archive from a
   trusted source; a matching hash alone does not establish publisher identity.
   Conversion tools, evidence collectors, development fixtures and the supplied site
   shell are separate artifacts and must not be added to the runtime ZIP.
3. Rehearse native upload/install, upgrade and uninstall/reinstall in an isolated copy
   with synthetic holdings and money. `tests/test_installed_package.py` (held),
   `tests/test_installed_package_fresh.py` and `tests/test_installed_package_upgrade.py`
   drive the real native uploader and check held, fresh and previous-runtime upgrade
   paths, migration history and retained data. Partial-schema refusal is exercised separately by
   `tests/runtime-install-test.php`, not by an injected installed-ZIP scenario. The upgrade test's previous runtime is a pinned
   local development commit, not a compatibility claim for an earlier release.
   Also run `tests/test_schema_mariadb.py` with the prerequisites in
   [coverage notes](testing-coverage.md#mysqlmariadb-schema-identifiers-and-native-lifecycle).
   This opt-in rehearsal proves fresh install and retained reinstall/upgrade on real
   MariaDB, including reference cleanup, with empty and prefixed table names. Retain its
   receipt separately; the default native install tests use SQLite.
4. Record the settings and acceptance checks below. Inspect both registrar accounts independently: binding/environment, exact accepted
   terms, annual renewal support, retail publication and selected seller. Install
   creates no account or sale offer. Processing, billing, optional reminders and every
   write feature remain off until their separate acceptance is complete. TEST evidence
   grants no live authority. NameSilo registration stays held in the shipping candidate.
5. Keep processing paused and native workers drained after replacing files. Validate
   retained managed native dates with `php artisan domains:native-expiry`. A nonzero
   result changes nothing. Run the explicit `php artisan domains:native-expiry --clear`
   cleanup, then validate again before restarting any worker. Installation does not
   repair these dates. Preserve provider-confirmed expiry and hosting dates. Repeat
   validation after privileged maintenance. Rebuild route caches and restart PHP/queue
   workers only after that check passes. Upgrades from a build with Secure editor
   remaps must rebuild caches before serving requests; reload every open admin editor
   tab because its saved component class no longer exists. Check the
   native staff setup, owned pages, cart/checkout and invoice DTOs with the supplied
   site shell. Cover engine absent, Enom-only, NameSilo-only and both holders; inspect
   long names, keyboard access, narrow layouts, errors and distinct renewal due/expiry
   dates. Verify that only owner-safe DTO fields reach customer HTML. Shell/browser
   acceptance is coordinated in its own repository and is not supplied by engine tests.
6. Before reopening, verify shared atomic cache/session storage, asynchronous queue,
   accepted account compositions, live-site confirmation, retry/worker deadlines and
   single boot authority against [runtime requirements](runtime.md). Check two scheduler
   instances for account fairness and one-use work on the deployment's database. Never
   activate the legacy Enom writer alongside Domains. An expired scheduler lease does
   not make an uncertain provider request safe to replay.

A green synthetic receipt establishes only the scope named in that receipt. Separate
provider acceptance, concurrent database schedules, delivered-mail checks and installed
shell/browser acceptance remain release gates. Adoption remains off until its native
mutation, consumer and concurrency prerequisites are independently qualified. The concurrent User-deletion/checkout gap is closed by owner locks and retained deletion intent.
Install migration 000036 before reopening domain checkout. It creates an empty owned table;
no native schema change or backfill is required. Missing storage refuses domain acquisition
and customer deletion. Drain old requests and restart web/queue workers to install the guard.
If a customer deletion fails after the guard passes, retry and finish deletion. Domain
checkout and adoption for that customer stay blocked until then. Do not clear or expire
the marker: an original request may still resume its DELETE. Completed deletions retain
the marker too; manual numeric user-ID reuse is privileged maintenance. A safe action to
cancel abandoned deletion remains deferred.

New Paymenter releases run full qualification, the pinned-source tripwire and the
supplied-shell override diff. Re-review changed hook/template dependencies and update
pins only with that reviewed change. A green upstream qualification needs no extension
release. Runtime activation must never refuse solely because a version string changed.

## Settings and safe defaults

Set deployment configuration before extension boot; rebuild configuration caches and restart
web/queue workers after changes. These keys are not environment-variable names. Record effective
values per account before upgrade; retained values do not reset to fresh-install defaults.
Staff changes require current permissions, a fresh confirmation and a reason.

| Setting or control | Fresh default / safe release value | Staff page and verification |
| --- | --- | --- |
| Enom identity: `domains.enom_identity.environment` | Unset; choose `test` deliberately | **Activation status**, **Registrar setup → Verify identity**. Use a new enabled Domains server; confirm TEST and the original lowercase UID, blank credential fields after reload and Processing Off. Saving verified credentials pauses processing. `live` permits identity/balance reads only; it supplies no live write composition or site acceptance. |
| TEST catalogue: `domains.enom_catalogue.enabled` | Off | **Registrar setup → Review catalogue acceptance / Import accepted ending**; **TLDs** for pricing, publication and seller. Accept exact terms, registry requirements and annual renewal first. Import does not enable processing, sales or select a seller. Re-import stale costs (60-minute freshness), publish and reselect as needed; old TEST cart lines are removed. |
| TEST physical calls: `domains.enom_test_daily_call_cap` | Integer `50`; use `0` to refuse all TEST requests | **Registrar setup → Enom TEST daily calls** shows used/limit for the UTC day, shared across every Enom TEST account and action. Failed, timed-out and reserved-but-unsent requests count. Invalid configuration or an unreadable counter refuses sends. Never reset the counter to recover; review before raising a limit. Local demo tasks accept only 1–200. |
| Registration: `domains.enom_writes.registration` | Off; TEST only | **Registrar setup**, **TLDs**, **Registrar requests**. Review Purchase at `https://resellertest.enom.com/interface.asp`, request interval (at least 25 seconds), exact terms, processing and a successful manual/preflight USD balance read less than one hour old. Require native funding before one Purchase; original order, charge, ownership and full-term expiry must confirm completion. |
| Renewal writes: `domains.enom_writes.renewal`, saved `domains_enom_test_renewal` acceptance | Off / unaccepted; TEST ordinary renewal only | **Registrar setup → Review TEST renewal acceptance** checks Extend, fixed TEST route, interval and USD balance. Review annual renewal catalogue support and processing separately. **Registrar requests** must show original charge and full-term expiry; grace and redemption remain unavailable in this composition. |
| Scheduled invoices: `domains_billing_enabled`; owner requests: `domains_manual_renewals_enabled` | Both Off; independent of renewal writes | **Registrar setup → Review renewal billing**. Enable one account at a time. Reload and check Scheduled renewal invoices / Owner renewal requests. Turning either off stops new invoices only; existing debts remain payable. Billing needs processing, active native authority and a genuine confirmed acquisition/adoption anchor; display fixtures cannot qualify. |
| Invoice calendar: `domains_issue_days`, `domains_due_days` | 21 / 7 days before confirmed expiry | **Registrar setup → Review renewal billing**. Require whole days: `1 ≤ due < issue ≤ 365`. Scheduled issuance runs from issue day up to, but excluding, due day. Uses billing-clock and confirmed-expiry dates without timezone conversion. Invalid saved calendars refuse new debt. Later changes retain issued amount, term and dates. |
| Owner auto-renew: domain `renewal_policy.owner_auto_renew` | On when absent; account invoice gates still Off | **Domains → Domains → domain** shows Owner auto-renew changes; customer **Domains → domain** has the switch. Off stops future scheduled invoices, complimentary cycles and Auto Pay. On restores only that preference. It does not change registrar auto-renew, cancel debt or prevent an otherwise eligible Renew now request. Staff/adoption stops and native cancellations take precedence. |
| Owner **Renew now** | Unavailable until owner requests and processing are enabled | **Domains → Domains**, **Registrar requests**, native **Invoices**. Request before the confirmed expiry date, including after the usual due day; a new late invoice is due today. The expiry date itself refuses online renewal. Offer only terms within ten years ahead; repeat the same request to reuse its invoice. A cancelled/review cycle needs staff disposition; do not create duplicate debt. Provider acceptance may support fewer terms than billing offers. |
| Renewal **Auto Pay** and native `settings.credits_auto_use` | No Domains Auto Pay switch; no charge without an eligible service billing agreement. Native credit use defaults On | **Registrar requests → renewal** shows Auto Pay result; native **Invoices** and service/payment-method pages show collection. New scheduled USD invoices apply sufficient allowed credit first, then attempt the owned enabled gateway agreement once for the remaining balance. Manual requests skip Auto Pay. Submitted is not paid; require native succeeded transactions. Declined, skipped and unknown have distinct outcomes; unknown must never be retried automatically. |
| Management: `domains.enom_writes.management`, saved `domains_enom_test_management` acceptance; `domains_dns_tools` | Off / unaccepted / Off | **Registrar setup → Review TEST management acceptance**, **Domains → Domains**, **Registrar requests**. TEST supports nameservers, lock, DNS, SRV and DS only. DNS/SRV/DS also need the per-server DNS tools setting; whole-list DNS/SRV edits require complete current inventory. Verify each change by readback; uncertain writes get reads only. Other management capabilities remain unavailable in this composition. |
| Inbound transfer: `domains.enom_writes.transfer`; `domains_transfer_endings`; creation/previous lock days | Off; empty allowlist; `domains_transfer_creation_lock_days=60`, `domains_transfer_previous_lock_days=60` (0–365) | **Registrar setup → Review catalogue acceptance / Review history acceptance**, **TLDs**, **Registrar requests**. Accept TP_CreateOrder route/interval, annual terms, registry support, USD balance and complete transfer charge history with timezone/lag/retention. Deployment sets ending/lock policy. Require fresh provider/RDAP checks and owner code via POST. Code edits stop at verified payment +30×24 hours (UTC); status/charge/owned added-year expiry must agree. No provider-supported TEST transfer fixture is accepted yet: keep full transfer acceptance blocked. |
| Customer reminders; native staff notifications | Optional renewal/expiry schedule Off; staff alerts use native inbox without a Domains toggle | **Registrar setup → Edit customer reminders / Review notification check / Review notification outcome**, **Registrar balances**, native staff inbox. Verify templates, channels, customer preferences and authorized recipients. Paid missing-code and recorded-credit notices remain independent of optional reminders. Confirm actual delivery separately; reviewing an uncertain handoff retains its claim and sends nothing. |

**Activation status** reports saved local gates only; opening staff pages is not a provider
probe. Leave NameSilo sales, adoption, public checks and watch notices off until their separate
acceptance is complete. Native server-edit permission includes access to saved credentials.

## Feature acceptance before reopening

Use isolated synthetic cases for failures, date boundaries and second cycles. Use only explicitly
approved TEST accounts, names, contacts and payment methods for provider checks. Record native
invoice/item/request IDs and actual TEST call deltas privately; browser submissions undercount
physical calls. A TEST receipt never qualifies live writes.

| Check | Required result |
| --- | --- |
| Registration and daily cap | Unpaid/partial funding sends nothing. One funded request sends once; duplicate worker delivery and reconciliation never resend Purchase. A low-cap isolated test refuses before send and keeps its reservation count. Capped scheduled reads can continue when calls return; a held write needs its supported audited disposition. |
| Billing and owner actions | Check issue/due boundaries, a request after due but before expiry, and refusal on expiry. Repeat requests reuse debt. Toggle auto-renew Off/On twice; verify staff audit, no registrar call and issued debt retained. Complete a renewal, then qualify a second cycle; a prior invoice/Auto Pay marker must not block it. |
| Collection | Check sufficient credit, no agreement, owned/foreign agreement, disabled gateway, manual request, decline, asynchronous/partial payment and timeout. Only settled full native funding permits registrar execution; unknown collection retains evidence and has no retry. |
| Management and transfers | Check stale/missing inventory, unsupported actions, foreign owner, stale form, paused processing and revoked acceptance. Confirm supported management by fresh readback. Transfer pending/needs-action/unknown never implies completion or resend. Qualify code/deadline recovery offline until a supported TEST transfer fixture exists. |
| Notifications and staff permissions | Check authorized native inbox recipient and link, no customer/unauthorized staff alert, duplicate suppression, missing template/channel, render failure and uncertain handoff. Inspect queue failures and delivered test mail independently. |

## One-time conversion checkpoint order

Follow the complete [profile procedure](../scripts/conversion/README.md), including its
Docker/Plesk sections where applicable. This summary does not replace process, cron,
lock, web-entry-point or permission inspection.

| Checkpoint | Required evidence before continuing |
| --- | --- |
| Source | Exact accepted `source.json` revision and schema; source/plan digests; no independently configured NameSilo server |
| Drain | Legacy jobs drained without deletion; running scheduler children finished; previous gate values recorded; all registrar gates off |
| Fence | Maintenance with no bypass, web/queue/dispatch entry points stopped, cache/overlap locks expired and both registrar runtimes parked outside active discovery |
| Profile | Dedicated profile: complete masked/inactive units and readable empty cron inventories. App profile: explicit operator inventory plus inspected process, cron, queue, database-cache-lock and rendered-maintenance fences |
| Evidence | Independently accepted authenticated ownership reads, one valid anchor per retained account in the same private evidence file, within the 24-hour window |
| Schema | Consistent pre-stage database/files/key backup with a proved isolated restore; explicit separate `--stage-schema` if needed; exact migrations and shape verified |
| Plan | `--dry-run` with exact canonical paths/profile; review unresolved attempts, pending IDs, unsupported history, payable invoices and all near-expiry triage |
| Apply | Pending holdings have recorded staff-managed renewal coverage; fresh pre-apply backup/marker and proved restore; every fence still valid; explicit `--apply` |
| Verify | Repeat dry run with runtimes still parked reports complete/already-complete and matching digests; IDs/counts, native money, null native expiry, reservations, encrypted history, reminders/templates/preferences and permissions match |
| Reopen | Install only Domains with all gates off; explicit recorded go/no-go before any entry point resumes; restore only intended workers/triggers, then maintenance |

The converter defaults to dry run. Required arguments are `--app`, `--source`,
`--source-revision` and `--evidence`. The dedicated profile requires `--units`; the
app profile requires `--host-profile=app --operator-inventory`. Do not combine profiles.
`--stage-schema` is separate from `--apply`; DDL is not an atomic rollback. An apply
requires `--backup-marker`, whose database/files archives predate the marker and whose
hashes and key proof match. A backup hash check does not prove a restore.

The read-only evidence collector is a separate, explicitly authorized provider step.
It accepts no command-line credentials. TEST scope, account anchors, one-name live scope,
private `0600` publication and replacement semantics are documented in the conversion
procedure. Replacing a file does not accumulate earlier account proofs. Converter dry
runs make no provider requests. Offline green does not authorize running the collector.

Do not cancel invoices or discard jobs just to pass conversion. Payable invoices and
unsupported terminal histories require the documented prerequisite or deferral under
the legacy deployment. Converted pending holdings need ongoing staff-managed renewal
coverage until verified adoption is available; the risk continues beyond initial triage.

On interruption, keep every entry point stopped. Park Domains again if incomplete
journal detection blocks boot. Resume only with identical source/plan/evidence inside
its validity window. A process, pool, deployment or supervisor restart invalidates the
stopped window. Repeat inspection and lock waits. Do not edit journals, mappings or
migration history to bypass refusal. A completed conversion with newer activity must
not be replayed. Preserve the current encryption key: previous decryption keys alone
do not preserve current-key authority seals after rotation.

## Rollback boundaries

Before any entry point is re-enabled, restore the pre-stage database, matching files
and encryption keys from the rehearsed backup into an empty recreated database.
Remove Domains from active discovery, restore the pinned legacy runtime to its original
active path, verify configuration/IDs/money and then restore only recorded legacy entry
points. Keep maintenance active until verification passes. A parked source-file archive
alone is insufficient. Keep an extra pre-apply backup for interrupted DML analysis.

The first restarted entry point crosses the simple-restore boundary, even while web
maintenance is still active. After any new obligation, credit, native write or provider
request, stop new work, retain current database/files/keys and reconcile an explicit
repair plan. An old dump can erase a real provider purchase or credit. Identify every
post-backup native and provider outcome before any reviewed restore or repair.

| Stop or rollback case | Required action |
| --- | --- |
| New invoice or sale pause | Turn scheduled/owner invoice gates off in Review renewal billing; withdraw intended sales and pause processing separately. Issued invoices, native credit and already submitted Auto Pay remain obligations. |
| TEST write acceptance revoked | Clearing renewal/management acceptance stops new sends; already sent work can reconcile while its deployment opt-in remains on. Unsent management work may send if acceptance is restored. Pause processing before changing acceptance. |
| TEST deployment opt-in removed | Stops that action's new writes, not necessarily provider reads. Another enabled TEST action can keep the shared runtime available: sealed uncertain management changes can still reconcile with management off and renewal on. Check each retained attempt and reader; stop relevant entry points/egress explicitly if all calls must stop. Preserve binding/evidence and arrange reviewed read-only reconciliation before reopening. Closing egress is not outcome proof. |
| Gateway charge or registrar request may have started | Retain current database/files/keys, invoice transactions, collection marker and original provider IDs. No blind gateway/registrar retry, downgrade or old-dump restore. Reconcile the original attempt first. |

Uninstall and migration `down()` retain evidence; they do not undo purchases, refunds,
invoices, reservations or conversion. A seller change changes future offers only.
It cannot migrate holdings, restore legacy authority or settle an unknown operation.

## Support decisions

Capture the safe operation/domain IDs, original registrar account and environment,
generation, state/reason, invoice/payment references, seal/send disposition, confirmed
expiry and last successful observation time. Inspect original encrypted evidence only
through current authorized staff tools. Check current account permissions and binding
before action; customer screenshots and native `Active` status do not prove ownership,
provider success or funding. Native service expiry stays null; provider-confirmed expiry
and invoice due dates are separate facts.

| State or report | Staff response |
| --- | --- |
| New offer unavailable | Inspect selected seller revision, accepted exact terms, price freshness, registry/annual renewal support and below-cost publication alert. Ask the customer to refresh the offer. Never fall back to another account |
| Old cart after a seller switch | Re-search/re-admit the line. Switching back does not revive an old revision receipt. Verify no native order/debt survived a refused checkout |
| Unpaid bound order | Retain original invoice, account and reservation. A later seller switch must not reprice or rebind it. Funding follows native payment evidence; missing transfer code is supplied only through the owned form |
| Paid setup/code hold | Inspect the safe setup/cutoff reason and the owned correction action. Before any credit, require current exact funds and definitely-unsent proof; only the supported audited action may refund |
| Pending or uncertain after a seal | Keep the original reservation/account and read/reconcile the original attempt. Timeouts, malformed replies and missing order IDs are not failure proof. No resend, fallback, automatic credit or manual unsent relabel |
| Below-cost renewal | Bill the owned retail and inspect the staff margin alert. Issued amount is frozen. No automatic surcharge/supplemental invoice or dispatch floor. A complimentary cycle needs separate audited term/currency/cap authority |
| Complimentary cap exceeded or cost unknown | Keep held and inspect current provider evidence. Do not increase the cap or infer zero cost. Any new authority requires its explicit audited workflow |
| Auto Pay unknown or submitted | Inspect the original gateway attempt, native transactions and webhook outcome. Submitted is not settlement; never clear the one-attempt marker or charge again to resolve uncertainty |
| Owner auto-renew off or late Renew now | Check the local preference separately from staff/cancellation/adoption stops. Issued debt remains payable; an eligible owner request can issue before expiry, even after the scheduled cutoff. Expiry-day requests go to support |
| Late/stale paid renewal | Retain issued debt, original base/expected expiry and holder account. Preference/seller changes do not erase it. Reconcile current eligibility; never purchase an extra year to clear a stale status |
| Cancelled/suspended native service | Retain the domain ledger, financial history and global reservation. Use the documented native lifecycle/owned stop controls. Cancellation is not evidence of provider nonownership or an unsent purchase |
| Missing native owner/order/service | Stop acquisition/renewal work and inspect the saved discrepancy. Retain the name reservation and history; do not recreate or reassign native identity from a provider observation |
| Failed/partial managed-record read | Preserve the last successful inventory and watermarks. Inspect the current hold; use the supported audited Resume scheduled reads action, then require a complete current-identity read before editing |
| Binding or key drift | Pause affected work, restore the original binding/key material and inspect authority. Re-verification with a different account does not rebind retained history |
| Notification handoff unknown | Inspect the original claim/provider delivery independently and record staff review. Never release/resend the original claim; queue acceptance is not delivery proof |
| Partial schema or conversion journal | Keep maintenance and workers stopped; restore/review exact schema/history or resume the guarded converter. No normal runtime repair or forced boot |

Repeated switching must leave both holding accounts operational for read-only facts,
financial reconciliation, renewals and reminders. Review account rotation lag, balance
forecasts, held renewals near expiry and failed native jobs independently of the seller.
If financial/expiry proof is incomplete, retain the unknown state and escalate with the
safe references and expiry deadline; do not silently abandon the renewal obligation.
