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
   with synthetic holdings and money. `tests/test_installed_package.py` drives the real
   native uploader and checks held/fresh/previous-runtime upgrade paths, migration
   history and retained data. Partial-schema refusal is exercised separately by
   `tests/runtime-install-test.php`, not by an injected installed-ZIP scenario. Its previous runtime is a pinned
   local development commit, not a compatibility claim for an earlier release.
4. Inspect both registrar accounts independently: binding/environment, exact accepted
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
mutation, consumer and concurrency prerequisites are independently qualified. The known
concurrent User-deletion/checkout gap is not closed by restarting workers.

New Paymenter releases run full qualification, the pinned-source tripwire and the
supplied-shell override diff. Re-review changed hook/template dependencies and update
pins only with that reviewed change. A green upstream qualification needs no extension
release. Runtime activation must never refuse solely because a version string changed.

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
