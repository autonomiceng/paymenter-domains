# Offer upgrade staging (B2)

This is dormant preparation. Installation adds nullable/defaulted fields; it never converts data,
publishes products, activates an account or switches a caller. No legacy store is dropped. B4 owns
the writer/projection and B6 owns the checked quiet window and activation. There is no dual writer.

At B6, stop/drain legacy catalogue writers and claims first. Keep them paused through comparison and
cutover: staging fields cannot prevent an old executable from writing its old tables. Capture
`UpgradeComparison::capture()` and retain its digest-only result with the recoverable deployment snapshot.
For each verified `AccountBinding`, call `UpgradeConversion::batch(binding, limit)` until `complete`.
The limit is 1–500 offers. No network, application bootstrap or command is invoked by the converter.

The union of retained TLDs, SellRows, accepted endings and price identities is traversed by a binary
ending cursor. Existing identities are never repointed. Newly discovered candidates are disabled and
unpublished. Each offer's staging data and cursor commit together. Retry after an interruption;
completed offers and accounts are no-ops. Source digests reject changing legacy inputs on resume,
including after completion; this is a one-time conversion, never continuing synchronization. A source
change requires a reviewed comparison/forward correction before B6, not clearing the marker blindly.

- Account defaults and nullable SellRow choice/longest/transfer overrides become B1 `Intent` data.
  Acceptance supplies missing choices only without saved defaults. Legacy `all` still ignores old skip rows. Derived action/registry/renewal
  flags do not become provider facts or explicit staff stops. The retained master Off becomes
  explicit register/renew/transfer stops; a newly created disabled candidate does not invent a stop. Hidden endings stay governed by the
  existing hidden-ending policy.
- Normalized observations live only in `domains_prices.catalogue_observation`. Exact price rows and
  annual fill cells compete by original observation time. Explicit dated disabled prices survive;
  omitted, null or malformed fill cells cannot withdraw valid costs. Equal-time disagreement stays
  unknown and is reported. Annual-to-total conversion uses integers and only an evidenced exact term.
  Unproven fill buckets and obsolete TLD cost-column units are reported, never interpolated.
- The accepted source schema has no independently dated registry/DNS/renewal facts. Such facts remain
  null, even when legacy acceptance/flags or cost timestamps exist. B5 resolves them lazily. Stale cost
  timestamps remain stale. Positive published annual USD renewal retail is retained separately even
  when the cost is missing or the account is unsold/deselected.
- Existing queue rows keep their cursor/claim/lease fields. New feed run/attempt/success/error fields
  carry the fill state; the new run starts beyond the legacy generation. This does not grant a legacy
  in-flight reader authority to write the new path. B6 must fence/drain those readers.

`UpgradeConversion::report(binding, before)` compares the original retained/native identity and row
sets, including seller revisions, settings, mappings, services, invoices, payments, credits and frozen
operation evidence. It also recomputes staged intent/costs/retained retail against legacy sources and reports mismatches.
The retained TLD/SellRow/price/acceptance union defines expected identities. Every offer must exist and
be converted; extra identities and completion/cursor disagreement reject compatibility and completion.
It reports completion, unchanged sources and issue counts; `activated` is always
false. Added staging candidates are explicitly excluded from the original-row digests. Any original
row difference blocks B6. Review unknown/current-offer availability separately; a compatible report
is not an activation grant or proof of installed MySQL concurrency/provider behavior. Retain the full
snapshot for recovery; digests alone are not a backup. Re-run conversion to prove idempotence.

Offline evidence is `tests/offer-conversion-test.php`: retained and fresh installs, prefix handling,
atomic interruption/rerun, conflicting/ambiguous/stale/disabled costs, preserved holder retail and
native obligations, plus 1,007 discovered offers across bounded batches. No provider or demo calls.

## Checked quiet window (B6a)

`Catalogue\UpgradeGate` adds an explicit maintenance seam. Nothing calls it during install or normal
bootstrap. Missing state means legacy operation. `pause()` persists a new generation; retained
catalogue writers refuse work while paused. Price reads also recheck their starting generation under
the server/account mutex, so a read cannot save after pause followed by rollback. These checks do
not stop an already running old executable. External maintenance and draining remain required.

`capture()` requires paused state and no catalogue/offer/observation claims or read reservations.
It binds the retained/native digest to the entire account set and this generation. `batch(binding,
evidence, limit)` wraps B2's bounded conversion. `check(evidence)` checks every account, staged
intent/cost/retail and untouched facts/publication fields, then compares global obligations once.
Account changes, incomplete conversion, source drift or changed frozen evidence refuse compatibility.
Claims are never cleared by the gate, even if their deadlines have passed.

This is a split before activation. `check()` always returns `activated: false` and
`activation_blocked: caller_cutover_pending`. There is no activation API. Staff, public, cart,
checkout and payment callers still use their existing paths. B6b must switch all of them, summaries
and native publication together, and compose B5's admitted read ports with our headroom and call caps.
The new worker must replace, never accompany, legacy `ObservationWork` on shared queue rows.

## Parent demo procedure (after B6b, never on B6a alone)

1. Record the exact deployed code/schema and recovery procedure. Enter maintenance for staff writes,
   public checkout/payment callbacks and financial jobs as well as catalogue jobs; otherwise the
   retained/native comparison cannot be stable. Stop `beco-demo-prices.timer`, the Domains scheduler,
   queue workers and manual Sync/import entry points. Record which services were running for restart.
   Let in-flight work finish; inspect both queue claim columns, observation-work claims and read-budget
   leases. A timeout does not prove that a process stopped. Do not clear claims or seed/reset tables.
2. Take a recoverable database and deployment snapshot while paused. Retain IDs and raw persisted
   evidence for accounts/fingerprints, seller choices/revisions, TLDs, products/slugs/mappings,
   plans/prices/settings, services/domains, invoices/items/payments/credits and operations. Include
   paid pending work and uncertain attempts with their frozen funding/cost ceilings. Record retained
   annual renewal retail for held and unsold endings. Keep customer data in the private backup only.
3. Deploy the reviewed additive schema/code while maintenance stays active. In an authenticated
   application maintenance console, instantiate
   `new \Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate`, call `pause()`, then
   `capture()`. Save its digest-only array beside the snapshot. Do not construct or register transports.
   This procedure needs an operator because an old process cannot honor a newly deployed code fence.
4. Enumerate **all** `Models\Account` rows in server-ID order. Derive each binding with
   `\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($account)`. Call `$gate->batch($binding, $evidence, 500)`
   until `complete`, then repeat to prove zero new conversions. Call `$gate->check($evidence)` and
   save the full result. Require `compatible` and `complete`, an empty `changed` list and empty
   `staging_mismatches`. Review reported unknown/current-offer availability against the approved
   policy; never turn acceptance or old flags into invented facts. Any other mismatch stops cutover.
5. **B6b prerequisite:** its reviewed runbook must supply the single activation call and atomic caller
   routing matrix. B6a supplies neither; do not bypass `activation_blocked` or edit state to invent an
   active value. Before that call, prove staff/public/cart/locked-payment agreement, stale evidence
   rejection, account rotation and the real admitted-port composition offline. Confirm the old
   consumer cannot restart alongside B5. Our planned feed/facts call counts are our budgets, not
   registrar limits. No connected reads are authorized by this runbook.
6. After the future checked switch, compare original IDs and frozen obligations again. Prove a paid
   pending request can continue without a duplicate attempt, an uncertain request cannot resend,
   retained holder renewal prices survive, and stale carts/payment evidence refuse new spending.
   Check the public theme interface, filters, staff permissions and summaries before reopening.
   Restart only the selected new catalogue consumer and the previously enabled financial services.
   Keep the old daily price refresher stopped once it has been replaced.
7. Before activation, rollback with `$gate->resume()` under maintenance, then restore the recorded
   legacy services/code. Additive staging fields remain compatible. The new generation invalidates
   old captures and in-flight reads. If legacy sources then change, the completed converter refuses
   reuse: review a forward correction instead of resetting markers. After activation, use B6b's
   reviewed forward-fix or coordinated recovery procedure; never restore financial data over new
   activity or run destructive schema rollback.

Offline B6a evidence is `tests/offer-upgrade-gate-test.php`: two accounts, 1,009 offers, pending/paid/
uncertain obligations, writer refusal, superseded price reads, generation/binding checks, claims,
tampered staging and frozen costs, one global obligation comparison and usable legacy rollback.
SQLite fixture results do not establish installed MySQL concurrency or provider acceptance.
