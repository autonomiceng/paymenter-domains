# Offer upgrade staging (B2)

## Required bridge upgrade before policy/store cleanup

Retained demo and production installations use two package upgrades. First install Domains bridge
`0.1.0` pinned to commit `89112442164558c4ab61ff32f210d1b102605112` and its verified package digest.
The version string alone is not a package pin. Keep that package available for recovery and older installations.
Follow the checked quiet-window procedure below on the bridge: stop/drain writers, snapshot, pause,
capture every account, convert bounded batches, repeat, compare, and authenticate the one activation.
Keep identities, annual holder retail and all paid/pending/uncertain obligations unchanged.

Only after its valid active receipt is persisted may the policy/store cleanup package replace the bridge.
The deployment tools call `Services\OfferUpgrade::requireCompatible()` using the candidate source before
replacing installed package bytes. It is read-only: an unconverted retained installation refuses with
the bridge version/commit to install first. No automatic conversion, active-state seeding or schema reset.
On the cleanup package, the extension lifecycle also refuses before extension-owned mutations.

Paymenter's native ZIP uploader replaces files before the upgrade hook and does not propagate its failure
reliably. It is not a supported direct legacy-to-cleanup upgrade route. Use the deployment tools' preflight
and staged package promotion; no Paymenter core changes. Preserve the original package on refusal.
Before activation the bridge can resume legacy work under maintenance. After activation use a forward fix
or a coordinated full restore accounting for subsequent financial activity.

The final cleanup package removes the converter and obsolete current-state stores. This runbook does not
authorize any demo/production deployment or provider request; those remain separately controlled operations.

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

The installed `Catalogue\CallerRouting` contract inspects the actual staff, public, cart, locked
invoice, payment, holder, status, native and runtime adapters and their shared Writer/consumer.
`check()` is report-only. `activate($evidence)` is the sole switch: an authenticated CLI staff actor
needs Domains view, policy, costs and Activate permission. It locks current native/financial rows,
all accounts, the same paused gate and source/staging rows, then repeats the complete comparison.
Changed account/credential/enrollment state, malformed staging, incomplete routing or any unreleased
claim/reservation (including expired leases) refuses. It never clears claims or constructs a transport.
A successful commit returns `activated: true`, the incremented `generation` and a digest. All callers
then select that generation; old queued payloads and legacy catalogue writers refuse. Cache invalidation
and a bounded local wake happen after commit. There is still one minute consumer.

## Parent demo procedure (after the complete caller cutover)

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
5. Require the reviewed complete caller-cutover head and its passing installed route/package tests.
   Still under maintenance, call `$result = $gate->activate($evidence)` in the authenticated CLI.
   Require `activated: true`, exactly one generation increment and a digest-only receipt; save it
   beside the earlier check. This method performs the authoritative locked recheck, including the
   compiled installed routing contract; an earlier compatible report supplies no authority. Any
   refusal keeps maintenance active for investigation. Do not edit the encrypted setting, seed active
   state, clear claims, reset conversion markers or activate a subset. Deployment never switches
   callers on a retained installation. Our feed/facts call ceilings are budgets, not registrar limits; this procedure grants no
   connected reads or paid proof.
6. After the checked switch, compare original IDs and frozen obligations again. Prove a paid
   pending request can continue without a duplicate attempt, an uncertain request cannot resend,
   retained holder renewal prices survive, and stale carts/payment evidence refuse new spending.
   Check the public theme interface, filters, staff permissions and summaries before reopening.
   Restart only the selected new catalogue consumer and the previously enabled financial services.
   Keep the old daily price refresher stopped once it has been replaced.
7. Before activation, rollback with `$gate->resume()` under maintenance, then restore the recorded
   legacy services/code. Additive staging fields remain compatible. The new generation invalidates
   old captures and in-flight reads. If legacy sources then change, the completed converter refuses
   reuse: review a forward correction instead of resetting markers. After activation, `resume()` and any legacy downgrade refuse. Use a reviewed forward fix or a
   coordinated full database/code restore accounting for intervening financial changes; never restore financial data over new
   activity or run destructive schema rollback.

Offline B6a evidence is `tests/offer-upgrade-gate-test.php`: two accounts, 1,009 offers, pending/paid/
uncertain obligations, writer refusal, superseded price reads, generation/binding checks, claims,
tampered staging and frozen costs, one global obligation comparison and usable legacy rollback.
SQLite fixture results do not establish installed MySQL concurrency or provider acceptance.

## New installations

The extension install/upgrade lifecycle automatically activates only a proven-new Domains installation:
no Domains tables, migration history, global or scoped settings, server identity (including deleted records), queued/failed Domains work or notification templates.
Unrelated Paymenter customers, hosting and financial records do not prevent a new Domains install.
An old empty installation, holding-only storage, orphan settings or partial history cannot take this shortcut.
Retained installations keep the maintenance procedure above, including authenticated operator activation.

Before creating tables, installation commits an encrypted initialization intent. While that intent is pending,
executable routing and legacy work refuse; there is no fallback. A completely uninstalled database also refuses
executable routing before its intent is created. Retry the same package's installation after an
interruption. The owned intent permits missing tables/indexes to be completed only while all Domains business
storage remains empty. Unexpected data or schema drift refuses; preserve the evidence and investigate.
Verification, empty-state recheck and the installed caller contract precede one atomic active receipt.
Repeated lifecycle calls preserve it. Initialization does not create accounts, enable processing or contact a registrar.
The schema-maintenance service alone never grants new-install activation authority.
