# Retained foundation storage

Slice 04 introduces models and a manually invoked retained-data migration. No extension entry,
listeners, native schema edits or automatic install/uninstall hooks exist yet. `down()` retains all
rows. `FoundationSchema::verify()` checks complete column metadata and index constraints against fresh declarations;
it refuses a mismatch or partial table set rather than repairing financial history. Partial indexes, non-InnoDB MySQL/MariaDB tables
and MySQL index prefixes are refused because portable metadata omits their weakened constraints. Verification uses a random empty
reference table on the same connection and removes it in `finally`; DDL privileges are required.
SQLite is exercised offline; MariaDB DDL/locking acceptance remains a release gate.

## Slice 41 mapping from Enom d2c55e2

- `enom_domains` → `domains_domains`, `enom_operations` → `domains_operations`, `enom_tlds` →
  `domains_tlds`: preserve IDs, native service/user/server/invoice/item references, operation keys,
  states, dates and decimal meanings; map proven source generation zero as specified below. No cascading foreign keys delete retained history.
- Existing columns preserve their Enom meanings, including encrypted snapshot/renewal policy/
  observation and payload/policy/cost, provider IDs, nullable global reservation, exact-term
  registration costs, annual renewal/transfer costs, sale holds and per-TLD rule overrides.
  `reminders` remains the source JSON-in-text representation; casts do not rewrite source bytes.
- All bound rows add `registrar_key` and `account_fingerprint`; operations also freeze generation,
  encrypted `attempt` and `funding`. Conversion must derive these from accepted binding and source
  evidence, preserving absent versus recorded-null execution, seals, refunds and obligations.
  Historical operation fingerprint/generation can be null when source proof is absent; new
  admission must require both and cannot treat null as generation zero. It
  must not invent evidence from current state. Unchanged encrypted columns retain ciphertext under the original key. Restructured encrypted
  fields require explicit decrypt/re-encrypt validation; original Enom rows stay read-only.
- `domains_accounts` is one immutable native-server identity record, with encrypted stable identity,
  explicit environment, nullable live-site binding and processing off by default. Credentials stay
  in encrypted native settings; no duplicate credential store. Binding guards follow in slice 06.
- `domains_prices` has one exact current price per server/TLD/action/term/currency, nullable unknown
  cost/retail, unit/source, freshness and revision. Account identity qualifies the row; unique server
  binding makes an additional registrar column in the price unique key redundant. Conversion of
  native acquisition prices and catalogue costs must retain units rather than guess annual totals.
- Shared default rule remains a native setting, as in Enom; `use_default_rule` and overrides stay
  per TLD/account. No unified retail table or cost floor is introduced. Seller policy arrives in 09.

This is a mapping design, not the converter or accepted installed-schema evidence. Customized
notifications/native financial rows remain upstream and must be explicitly covered by slice 41.

## Authoritative conversion and evolution rules

Slice 39 must call native Migrator followed by `FoundationSchema::verify()` directly on every
install/upgrade; migration history can skip `up()`. Do not use the exception-swallowing native
`ExtensionHelper::runMigrations`. Fixtures exercise a recorded migration followed by standalone
verification. No native hook exists in this slice, so installed compatibility is still unproved.

The converter moves `policy.dispatch_attempted_at` to `attempt.dispatch_attempted_at` and
`cost.execution` to `attempt.execution`, then removes those source keys from the new rows.
It moves `policy.authority_generation` to the nullable column and removes the source key. Preserve
key absence versus present-null exactly. Historical account fingerprint comes from proven source
binding; unknown operation binding/generation stays null and cannot authorize new work. Domain,
TLD and account binding must be independently proven at conversion or conversion stops. The
original Enom tables preserve ciphertext as read-only audit; new operation policy/cost are
re-encrypted after restructuring, with no runtime alias or dual write. Contradictions stay unresolved.
`funding` is the sole settled-funding and refund evidence snapshot. Move `cost.refund` to
`funding.refund` preserving the complete double-refund guard, then remove the source key; absence
and recorded-null remain distinct. No runtime refund fallback reads the old cost field. Plain funding_basis/reason/authorized_by
retain their administrative meanings as checked projections and cannot independently authorize
spending. Slice 41 must enumerate/map source funding keys against the accepted final source schema.

`domains_prices` is authoritative for new exact wholesale/retail/source/hold facts. Retained TLD
cost/renew_price/sale_holds fields are conversion-source audit data, never a runtime price fallback;
TLD rule/override columns remain live inputs. Native plan prices are publication projections of
price rows. Slices 07/08 must implement revisions/refresh and avoid updating competing authorities.
Account identity fingerprint is SHA-256 of registrar/environment/stable identity, independent of
server ID and APP_KEY; its global unique constraint prevents duplicate account/server bindings.
The full AccountBinding fingerprint still includes server ID. These unsalted hashes can expose
low-entropy login guesses to database readers; identity encryption does not conceal that
correlation. Hide both from generic serialization; neither proves provider ownership.

Binding columns are the sole authority. Validate embedded `account_fingerprint`, `server_id`,
`authority_generation`, `confirmed_revision` and `provider_domain_id` in operation policy, domain
renewal_policy and observation against proven source state, then remove those embedded keys in
converted rows. Preserve other policy/observation facts; restructure and re-encrypt where needed.
Never copy an Enom fingerprint as the new fingerprint. Prove it by recomputing
SHA-256 of `environment + ':' + strtolower(uid)` from the bound native server and comparing
exactly with every source fingerprint present; contradictions stop conversion. Positive provider
verification must establish that same account before deriving the new hashes. Enom stable identity
is its lowercase uid; NameSilo requires a positively verified stable account identifier with its
provider-defined canonical spelling, never the API key or a caller-supplied label. Unknown
normalization or identity blocks conversion. Slice 41 must enumerate all embedded source keys.

This unreleased schema can evolve with coordinated declarations/migrations. Before release the
verifier must describe the final combined schema, with explicit versioned upgrade acceptance;
never run an obsolete foundation verifier against a later schema. Collation/type drift fails closed.
Partial DDL failure requires restoring/verifying the full retained backup (or an audited proof that
all affected tables are empty before restarting fresh, with a reviewed recovery tool in slice 39); reinstall never invents lost history.

## Reservation authority (slice 05)

`CanonicalName` is shared by neutral queries, NameSilo parsing and domain creation. It accepts
one-character labels, ordinary ASCII, case/one trailing dot normalization and explicit compound
TLD suffixes. It refuses IDNs, reserved hyphen forms, subdomains for a selected TLD and control
characters. Syntax never establishes availability, premium eligibility or ownership.

`DomainReservations::reserve` creates the ledger reservation in a transaction. Checkout must wrap
native service/invoice/operation writes in its outer transaction, acquire server/account then
policy/catalogue locks before domain locks, and roll back on its duplicate exception. The global unique key arbitrates absent-row races.
Release locks the domain then all operations in ID order. Every operation must be rejected with
matching service/account and positive generation and explicit encrypted terminal proof. Missing
proof, domain-level provider IDs/expiry, active/pending/unknown work and ownership block both paths.
The unsent path also refuses any execution, seal ambiguity or operation-level provider reference/ID.
The sent/provider-proof path may retain operation IDs, execution and seals; explicit rejection and
no-charge proof must resolve them.
Cancelled records only release with definitely-unsent proof. A provider-proven rejection can
release an acquisition with explicit sent/no-charge proof. Native cancellation alone never releases.
No automatic refund, fallback, replay or invoice cancellation behavior is added.

Normalized `attempt` terminal proof fields are `disposition`, `rejection_basis`, `send_state`,
`seal` (unsent path), and `charge_absent` (provider proof). Presence of `execution` or
`provider_reference` or `dispatch_attempted_at`, even null, blocks unsent classification; legacy `cost.execution` and
`policy.dispatch_attempted_at` also block it. Conversion must positively derive terminal proof,
never seed it merely from a rejected status. Slice 19/22 owns authoritative evidence recording.
Contradictory charges, order candidates or actual cost block both paths. Domain and operation
rows refuse ordinary deletion; operation bindings/actions and holding bindings are immutable.
New holdings start at generation one; historical zero/null generations cannot release.
Slice 41 atomically maps a proven Enom generation-zero authority to generation one on the domain
and every operation proven to belong to that authority, keeping zero in the read-only source audit.
This is an epoch translation, not proof of an operation's account/terminal outcome. Unknown
operation authority stays null; incomplete/contradictory mappings stop conversion, never default
to one. Valid converted terminal reservations can release only after all ordinary proof gates pass. Only the reservation service clears reservations in ordinary runtime. Raw SQL, bulk Eloquent updates/deletes and quiet saves are
reserved for reviewed conversion/maintenance and can bypass model guards. These SQLite fixtures
prove transaction rollback and uniqueness, not MariaDB concurrent checkout or lock ordering.

## Account binding authority (slice 06)

`RegistrarRegistry` registers named Enom/NameSilo entries with credential fields, a read-only
positive-identity verifier and a reader factory. No production entries or provider identity probes
ship here. A customer/staff label or API key hash is not accepted as proof. Synthetic verifiers
exercise the integration seam; provider acceptance remains open. Writer handles are refused.

`AccountBindings::configure` verifies outside database transactions, then locks the native server
and account row in that order. A successful first save encrypts the identity and native credential
settings; rotation must verify the same stable identity/environment/registrar/server fingerprint.
Rotation pauses processing. A credential HMAC binds the verified credential set to the accepted
account; it detects unverified native-setting edits and does not stand in for account identity.
Native settings are marked encrypted before their first value/save. No native schema is changed.

Bindings cannot be created from labels, repurposed or deleted through ordinary model edits.
AccountBindings uses a narrow synchronous internal Account write scope after its checks, restored
in finally; ordinary model site/processing edits are refused too. Explicit trusted PHP bypasses
(including the internal scope and quiet saves) are not authorization APIs or native form inputs. Live access requires an
explicit current HTTPS application URL, with a one-time immutable site confirmation. Processing
starts off and must be explicitly enabled. A copied database at a different URL cannot read/live
process; restoring the original URL does not prove a separate copy is safe, so deployments still
require independent operational controls. Pausing remains available when credentials are broken.
The dispatch guard only checks configuration, never funds/admission or prior outcome. Slice 19
must recheck it inside the claim transaction after server/account locks; unlocked reader/guard
calls alone do not serialize dispatch against rotation or pause. The global lock order is server,
account, policy/catalogue, domain, then operations in ID order; MariaDB must prove interleavings.

Configure failures and stored-credential parsing failures discard internal exception traces without chaining,
including with PHP exception arguments enabled, so captured plaintext does not escape diagnostics.
Application encryption-key loss refuses decryption; changing the active HMAC key requires verified
reconfiguration after restoring decryption access. Keep previous encryption keys in
`APP_PREVIOUS_KEYS` until a reviewed tool has re-encrypted every retained encrypted column:
configure only rewrites native credentials, and immutable account/ledger fields cannot be rotated
through ordinary model saves. Registrar token rotation preserves the binding
only after the injected verifier positively proves the original account. Enom UID identity is
lowercased before storage/hashing; NameSilo verification must return its canonical stable ID.
A deleted native server cannot silently reclaim an existing account on a new ID: recovery requires
an audited ID-preserving restore, or a separately reviewed migration proving every retained
holding/operation and live-site binding. No automatic retirement or rebind path ships here. No native global hooks
exist yet: future slice 39 must route every native save/dispatch through these guards. Native
server disable/extension-change hooks must pause processing so re-enabling requires an explicit
resume. Slices 37/39 must prevent native EditServer's all-settings fill from exposing decrypted
credentials/HMAC in public Livewire state; neither internal setting key may be declared in Domains
config. The service is not an installed native-admin secrecy boundary. Direct SQL, bulk Eloquent
updates/deletes, quiet saves and privileged malicious code can bypass model events and are not a supported runtime
editing path. Real provider proof, native UI, MariaDB locking and installed-runtime acceptance remain
separate gates.

NameSilo driver work (13/20) requires proving that its API exposes a stable account identifier;
if it cannot, configuration remains blocked rather than accepting a key or label. Moving a held
domain between our accounts needs a separate schema/authority decision: current binding fields
are immutable and the global reservation forbids a second holding for the same name.

## Pricing rules (slice 07)

Adapted from Enom 0.5 frozen revision `444f31c` Services/PricingRule.php and
Services/Catalogue{,Runtime}.php, including accepted partial republication behavior.
`PricingRules` uses one native `domains_pricing_default_rule` per account/server, shared
by that account's default-rule TLDs; overrides remain on each bound TLD. Retail tables
remain separate across accounts. Republishing returns failed endings and continues.
`MarginPolicy` assesses per-year published list retail against per-year term costs before
tax/discount (same currency): below-cost acquisition terms
are held with staff alerts; below-cost renewals continue with alerts. These assessments
are publication inputs, never obligation/dispatch floors or complimentary authority.
Unknown costs prevent new price publication; renewals retain prior retail with an alert,
never a billing/dispatch hold. `RetailLine` checks positive net totals independently of margin;
a positive discounted cart line may be below cost. Currency conversion and provider rounding are not inferred.
`PricingRule` retains the source four-decimal wholesale/two-decimal retail bounds;
provider evidence with greater precision requires a separately reviewed exact adapter.
Slice 41 must rename the source `pricing_default_rule` setting to `domains_pricing_default_rule`.

## Current prices and holder display (slice 08)

`PriceCatalogue` refreshes a TLD atomically after provider reads finish. Exceptions, unknown
reads, empty/malformed batches and older observations retain all that TLD's prior prices and
revisions, report failure and continue. Only `CatalogueRate` with explicit null `perYear`
withdraws acquisition terms; renewal keeps its last positive retail with an unknown_price alert.
Omission retains prior evidence. A successful write increments revision. Failed reads
never extend freshness. Defaults republish through `PriceCatalogue::republish` independently.
`CatalogueRate` is a verified per-year rate for its specific registration term, preserving
Enom rounding before multiplying retail by years. Renewal/transfer accept annual term one
only. Adapters must establish a selected-term rate, never extrapolate an annual NameSilo
listing. Rows store exact term-total wholesale/retail with source-unit provenance. Unsupported
precision/currency/terms fail closed, retaining prior costs. No legacy TLD cost fallback exists.
`current` requires known fresh prices; snapshots freeze ID/revision/binding/source/money for
later encrypted obligation persistence. `HolderPricing` looks up the domain's frozen account,
independent of seller flags; below-cost renewal prices remain usable with staff alert codes.
`HolderPricing::renewal` returns a separate RenewalPrice: fresh/current or retained published
annual retail, with a stale/unknown_price alert. A cost outage, rule failure or expired freshness
never removes the last published positive renewal retail. This is billing retail authority;
fresh `current` cost evidence is separate and is never a new dispatch/obligation floor.
Slice 24 must project the locked holder retail into native service.price for future billing;
native Paymenter bills from that field. Already-issued invoices retain their original amounts.
The save guard allows lifecycle-only saves regardless of old price or price mode; it refuses
binding changes and only checks dirty price against fresh current retail. Unknown evidence
cannot authorize an arbitrary price edit. Trusted plan/config/complimentary transitions remain
owned by the later integration. No observer is installed: 24/39 must invoke it under
ordered locks and cover native config relations, annual plan transitions and complimentary
permissions. Issued invoices are never recalculated by these services. Native UI remains open.

Slices 12/13/14 must explicitly map neutral QuoteEvidence into CatalogueRate: verify matching
account/action/term/currency, reject unknown/null quote costs (ResultReason has no confirmed
no-price signal), and require separate positive no-price evidence before constructing null.
Annual quotes prove one-year terms only; TermTotal must divide by the selected term exactly
at four decimals, after trimming only zero excess digits from six-decimal Money. Do not round
or extrapolate. Future observation timestamps must be refused by the adapter's accepted clock
before sync, so faulty evidence cannot wedge later reads. These adapters do not ship here.
Slice 14 couples acquisition eligibility to annual renewal, live term bounds and accepted
TLD capabilities. Holder renew_enabled semantics must be decided for 23/24 independently
of seller flags. Native-disabled servers are uniformly read-only for sync/republish; existing
holder retail remains readable. lock=true requires server/account/policy/TLD locks first.
Snapshots redact cost/binding from generic JSON/debug and refuse PHP serialization.
Slice 24 must hold server/account/TLD locks before renewal(), neutralise upstream cart and
RenewServiceService expires_at writes before installing the null-expiry guard, and update
future native billing projections only. Slice 14 must skip retained out-of-range registration
rows during republication when term bounds change, and precheck disabled native servers before
provider reads; current write refusal alone deliberately does not avoid the read callback.

## Seller policy and checkout locks (slice 09)

One retained domains_sales_policies row per canonical TLD names a default account or none,
independent acquisition flags and monotonic revision. SellerPolicies is its ordinary writer;
raw SQL/quiet/bulk writes and internal PHP scopes are privileged maintenance bypasses.
Initialization's absent-row unique race aborts. Changes require expected revision, a configured
processing target, accepted TLD flags, fresh annual renewal and an eligible acquisition term.
Only terms in 1–10 within configured bounds qualify; disjoint/reversed bounds yield none.
Changing policy never repoints products,
old orders or holders. Switching away from disabled/paused old sellers needs no old-account lock.
Slice 14 must publish only accepted capability/registry/action/renewal flags. No network here.

Paymenter 1.5.9 Cart::checkout already owns the native transaction. Slice 16/17 gathers ALL
Domain selections and calls SellerPolicies::admit once, AFTER native user/coupon/product locks,
BEFORE domain/operation writes. admit requires that outer transaction, adds no nested transaction
or callback, and returns immutable price snapshots in input order. All native service/invoice/
item/reservation/operation writes commit together in that SAME transaction. Any stale line,
duplicate, deadlock or timeout escapes to the native rollback; never catch-and-continue or retry.
The reservation must derive account identity from its admitted snapshot, never independent input.

Global checkout lock prefix: native user, coupon, products; ALL Domains servers by numeric ID,
then accounts by server ID (including their credential settings), policies by canonical TLD,
TLD rows by ID, price rows by ID, domain rows then operations by ID. The full batch is locked
before per-line validation. change uses target server/account then the SAME policy row then
TLD/prices; disabling sales takes only policy. No old-account or native-product write in a switch.
If checkout wins, old bindings commit first. If switch wins, stale carts abort. Switch-back raises
revision again. Policy/price ID/revision, bindings, flags, terms and annual renewal are rechecked;
there is no fallback seller. DTO possession grants no admission or dispatch authority.

requireProcessingLocked validates supplied locked server/account models and current locking
credential reads, never older snapshot re-queries. Locked price validation requires the caller's
locked TLD and current locking shared-rule settings, so repeatable-read cannot hide a pause,
credential edit or rule change. MariaDB acceptance must establish native checkout's older read
view, commit a second connection's pause/override/default-rule edit before Domains locks, and
prove stale evidence refused. Also test reversed multi-account cart order and switch/duplicate/
1062/1213/1205 whole-checkout rollback. SQLite establishes sequential semantics only.
Slice 15 price publication must lock native products/plans BEFORE Domains rows, never afterward;
otherwise it can cycle with checkout. Native lock-prefix integration remains 16/17 acceptance.

Slices 39/42 must call FoundationSchema::verify AND SalesPolicySchema::verify after Migrator,
even on skip; both migrations retain data on down. No native/foundation columns change.
19/20 dispatch frozen old holdings without requiring current default seller, while rechecking
processing, funded authority, ownership and unresolved outcomes. No financial runtime ships here.

The server row lock serialises every catalogue/price/default-rule writer, including future
TLD admin, 14 and 15 paths. Batch admission orders price locks by ID; change validates renewal
then acquisition terms under its server lock. Default-rule settings may be locked at validation
under that same server lock. No future writer may omit this prefix.
Slice 24 needs a locked renewal API accepting the caller-held TLD and current locking rule/
price reads: holding rows before calling the existing unlocked renewal() is insufficient in
native CronJob's enclosing repeatable-read transaction. Test an older native cron snapshot.
Slices 16/17 must compare each native line list price to its admitted snapshot retail and abort
ALL checkout on mismatch, handling coupon/net authority per plan 16. Install one batch call
at the native order-creation boundary after ALL product locks and before service/invoice writes;
there is no existing between-locks hook, and per-item admission is unsafe.
MariaDB acceptance must EXPLAIN batch TLD/price locking queries: require suitable indexed range
access, not broad primary-key scans that lock unrelated accounts under repeatable read.

## Shared catalogue acceptance and sync (slice 14)

`CatalogueAcceptances` retains an encrypted native setting per immutable account, with optimistic
revision, accepted suffix/action/exact-term USD unit evidence, registry support and safe annual
renewal acceptance. Empty actions/endings revoke acceptance; missing/corrupt/unreadable evidence
fails closed. This internal service is not a provider verifier or staff authorization boundary.
No production acceptance or account verifier is supplied. Native admin must exclude this internal
setting from public state (37/39). Acceptance changes immediately invalidate acquisition admission;
a successful new sync is required before the new revision can publish. Holder retail stays readable.

`CatalogueReader::catalogueBatch` returns neutral bound entries, with null/missing entries meaning
failure, never no-price. NameSilo reads getPrices once for all accepted suffixes/actions, using literal
compound keys and verified annual units only. NameSilo full-domain read requests validate canonical
syntax; quotes resolve the longest literal suffix in sanitized account prices. These reads grant no sale
authority; publication still requires durable acceptance. Enom reads live term/registry facts and exact selected
term prices. Enom source positive no-price alone produces null rates; NameSilo omission has no such
signal. Shared `CatalogueMapper` consolidates advisory quote conversion; its nonpersistable rates
are refused by sync/import. Quotes cannot substitute their short lifetime for catalogue freshness.

`CatalogueSync` prechecks enabled server, verified credentials/binding/site and durable acceptance,
then finishes all provider reads before transactions. It rechecks locked current configuration and
acceptance revision under server/account/TLD locks before committing each ending independently.
New TLD rows start disabled; import does not select sellers. Accepted capability/registry/safe-renewal
facts qualify publication, which also needs current annual renewal and an eligible exact acquisition
term. Seller change, selection and batch admission recheck current acceptance, stored revision and the
latest annual renewal row’s live term proof, so widening configured bounds cannot revive old terms.
Configured bounds are reread under locks; live narrowing never automatically broadens them again.
Staff may deliberately widen them within accepted live terms in 37. Out-of-range retained prices
are skipped during rule republication, preserving their bytes; omission/failure never withdraws prices.

The default local cadence is 1,800 seconds plus a 1,800-second failure margin (one-hour freshness),
shared by both drivers, independent of NameSilo's short quote horizon. Observation starts before the
batch/read, so latency cannot extend freshness. Acquisition stops at expiry; retained holder retail
continues with stale/unknown alerts. The future account-fair scheduler (31/38/39) must use this cadence;
no scheduling/hook/native product publication exists here. Rule and margin semantics are unchanged.

The legacy cost-only `PriceCatalogue::sync` drops publication proof on every touched row; it cannot
renew accepted sale eligibility or substitute a longer lifetime. Enom's direct source-rate helper is
also nonpersistable and takes the shared policy type. Only accepted batch import stamps publication;
rule republication preserves those stamps without changing observation time. Price revisions advance
once per imported rate. Native acceptance envelopes explicitly retain USD/exact-term annual units and
are bounded to 32 KiB plaintext before any write, safely below encrypted native TEXT capacity.
Corrupt acceptance remains unavailable; authorized repair/revocation and audit tooling belong to 37.

`CatalogueSync::refresh` accepts an optional trusted subset of accepted suffixes for bounded scheduler
chunks. Default Enom ordering rotates each cadence; the scheduler must drain all accepted suffixes
with fresh contexts/budgets before expiry, servicing holders independently of sellers. A slow batch
plus a missed refresh can cause a fail-closed sales gap; the failure margin is not an uptime promise.
No expiry is extended to hide that gap. Slices 31/38/39 own durable cursors, retries and account fairness.
NameSilo registry/renewal support here is explicit durable account acceptance, not a live capability
probe. Its advisory constructor quote acceptance grants no publication authority. Slices 18/37 must
refuse unsupported registry attributes; one malformed account price row fails the whole NameSilo batch.
Enom transfer allowlists/TransferOrders support remain mandatory 27/28 acceptance gates. Settings
locks occur after the TLD lock during import; the common server lock serializes cooperating writers.

## Native catalogue projections (slice 15)

`NativeCatalogue` publishes permanent hidden account/TLD/action products, four neutral markers,
account-qualified slugs and exact-term recurring annual-unit plans. The native category is created if absent.
The native category lock serializes first creation (upstream slugs are not unique), then existing
products/plans/prices lock before server/account/TLD/price evidence. Checkout never locks category.
Publication uses PublicationEligibility and current annual renewal; it never repoints servers.
Withdrawals remove acquisition price projections, retaining product/plan identities and an annual
plan on each product for holders. These projections grant no checkout or spending authority.
No publication hook or installed entry is registered yet; 37/39 own authorized invocation and
native marker/product editing guards. SQL/privileged edits remain outside the service boundary.

Native plan prices are acquisition projections, including the one-year plan; holder renewal display
uses service.price on that same product, not its plan price. Slice 17 freezes checkout and sets the
annual holder price. Slice 24/39 must intercept native coupon calculatePrice/save and null-price
free-renewal paths before installation; acquisition withdrawal must never become a free renewal.
Publication uses stock=0 to withdraw sales while retaining all product/plan identities. It refuses
upgrade links in both directions and config-option links before Domains locks; mapping also refuses
these links. Slice 39 must prevent them and presentation/identity edits being re-added. Existing
invalid hidden/quantity/slug edits deliberately fail closed pending those guards. Creation of hidden
projections for disabled TLDs is bounded operationally by authorized publication in 37/39; avoiding
unused first projections is a later optimization. Native publication invocation moved from cat14's
15 hook gate to 37/39. Scheduler31/38/39 must republish on sync and freshness expiry. MariaDB EXPLAIN
must verify publication's unlocked slug/marker discovery followed by primary-key product locks;
no FOR UPDATE over the unindexed slug/marker OR scan. Native product locks still follow native
checkout order; deadlocks/timeouts abort the entire checkout, never resume a partial batch.

Slice 16 keeps an opaque, five-minute eligibility receipt in the server cache, bound to the
cart, canonical name, account, product and exact plan. Native login can adopt a guest cart;
checkout independently verifies the authenticated owner inside its native transaction. A fresh accepted availability read must say
premium=false and available=true. Rendering fields makes no provider request. Cart create/update
performs that read outside transactions; checkout performs no provider read. Transfer checkout
gates and owner code/cutoff/timeout rules are specified in [transfers.md](transfers.md); codes follow checkout.
Native Order Creating uses the same cached item batch as vanilla checkout, after all native
user/coupon/product locks and before invoice/service inserts. It calls SellerPolicies::admit once for
ALL domain selections. A receipt or DTO cannot replace that call. Native list row equals the admitted
retail; discounted final unit total already includes coupons. Subtract native original tax once to
require positive net, independently of margin. Each domain line has quantity one and no config options.
The admitted batch exists only for this transaction and clears on outer commit/rollback. Slice 17
consumes it synchronously; contacts/registry validation (18) and claims/dispatch (19/20) remain required.

Presentation32/39 must translate customer-correctable refusals to native DisplayException or field
validation, and offer an explicit re-edit/recheck path after receipt expiry outside the checkout
transaction. Generic DomainException handling is not ready for installation. Slice39 must install
exactly one shared CartAdmission instance before InvoiceBinding; the double-adapter test only
simulates an earlier listener changing cached native prices.

Slice 17's internal InvoiceBinding boot follows CartAdmission boot (installation39 still closed).
Order Creating preflights ALL annual renewal projections, then locks ALL reservation-name matches ordered by
Domain ID and operations by ID. Native Service creating consumes only the current admitted batch;
InvoiceItem Created synchronously reserves the global name, inserts its immutable account/generation
operation and projects the same product's annual plan/service.price and null native expiry. The native coupon association
is retained for usage limits;24/39 must intercept coupon repricing before installation.
The original invoice total/description/checkout term and product/plan IDs remain frozen in encrypted
snapshots; estimated wholesale is separate. Contacts/registry18 remain setup_required; no spending,
jobs or provider writes occur. Duplicate item events only validate current binding and return; they
never reprice, recreate history or replace snapshots. New bindings outside admission refuse.

InvoiceFunding::lock captures the COMPLETE native invoice/items/transactions/services/products prefix
before domain/operation locks. Its private context cannot accept selected subsets and expires on
outer commit/rollback. requirePaid validates frozen native/owner/account/generation/name identity,
USD and positive domain amount; only succeeded signed payment amounts count toward the FULL native
invoice total, including hosting and other lines. Paid status alone is insufficient. This yields
funding evidence only: engine19 must revalidate it under ordered claim locks alongside processing,
ownership, reservation, generation, prior outcomes and leases/seals. A copied snapshot never grants
spending authority. 24/39 still own annual repricing/native save guards before installation.

Slice24 must reconcile holder-price tax basis: native service.price includes exclusive tax while
HolderPricing currently compares untaxed retail. It must also intercept native paid-invoice renewal
activation/expiry and route renewal invoice items, preserving the purchased term and provider expiry.
MariaDB acceptance must cover reservation-name range/gap locks, neighbouring-name deadlocks and
1062/1213/1205 whole-checkout aborts. Existing-name conflicts currently refuse during binding;39 must
ensure no native jobs escape the outer transaction (or add an earlier reservation preflight).

## Contact setup and retained profiles (slice 18)

Invoice binding now captures four native-owner contact roles and the exact accepted registry schema
in encrypted operation.payload. Later native profile edits never replace a held/queued snapshot.
ContactSetup validates frozen values; its model guard permits only the internal audited recovery scope
to change contact setup. That scope is privileged internal PHP, not a route authorization boundary.
No registry-specific inputs are accepted: `.us/.ca/.eu` and nonempty/missing schemas stay off at
publication and validation until separately accepted. An explicit empty ordinary schema is required.

ContactRecovery owns its transaction: native users, full InvoiceFunding prefix for acquisitions
(service/product for nonbillable contacts), server/account, domain, all operations in numeric order.
Paid invalid acquisitions can be held; owners or fresh `admin.domains.manage` staff may recollect
the OWNER's details with a reason and bounded audit, only for the same contact-held definitely-unsent
obligation. Register returns queued; transfer stays held under [transfer rules](transfers.md). Registry/EPP/NS/deadlines and financial
rows are preserved. Staff may terminally reject held nonbillable contacts as audited definitely-unsent,
never credit or release the name. Any send, claim/lease, recorded execution (including null), provider
reference, charge, candidate, unknown attempt field or financial refund blocks recovery.19 must align
this refusal contract with its sealed-attempt representation; neither API creates claims/jobs or spends.

`domains_contact_profiles` is retained on down, encrypted, immutable and unique by server/provider-ID
fingerprint, with owner and immutable account identity. ContactProfiles is an internal evidence sink:
20/30 must prove fresh contactAdd and non-default readback before recording, never adopt account
inventory/customer IDs. Four roles resolve LOCAL IDs through owner/account predicates to provider IDs.
No profile update is allowed; changed contacts require a new provider profile and confirmed association
in 30, so another customer's domains cannot be changed through a shared/default profile.301/302 remain
purchased discrepancies needing reconciliation/correction (21/30), never refund/replay/unsent proof.
39/42 must invoke ContactProfileSchema::verify after Migrator even when migration bookkeeping skips;
this slice adds no entry point, provider writes, routes or production identity/profile acceptance.

This section supersedes17's setup_required placeholder statement above. Before ANY acquisition
claim/seal,19/20 must call ContactSetup::requireReady under their current locks: explicit false
setup_required, true registry_supported, and validated frozen contacts/registry. Queued is not
dispatch permission. Only ContactRecovery may release contact_details_required; it refuses foreign
held codes. Registry-unsupported paid holds stay held; transfer timeout/disposition limits are in [transfers.md](transfers.md).
Native identity checks consume current locked models, never a plain repeatable-read re-query after
permission reads. MariaDB acceptance must test that older read-view/cancellation/owner/route race.
Profile fingerprints use an HMAC keyed by a random secret in encrypted retained profile details.
The first retained server profile anchors that key under the server/account lock; APP_KEY rotation
with previous decryption keys preserves uniqueness. Missing decryption keys or corrupt anchors refuse
recording.39/42 must preserve encrypted keys during rotation/re-encryption; never delete the anchor.
Sequential provider IDs cannot be enumerated from the index alone. NameSilo's empty schema originates from per-ending durable registry acceptance,
not a live schema probe; that acceptance and the local blocked endings still apply.39 must register
admin.domains.manage and coordinate migration000003 with the finance lane before installation.

19/20 must schedule ContactRecovery::hold after a contact-refused claim transaction commits (e.g.
DB::afterCommit); it owns its transaction. Stale setup flags also enter a recoverable contact hold;
audited recollection reasserts readiness only after frozen registry and fresh owner contacts validate.
Paid awaiting_payment may become held then queued here;19 must still confirm full native funding
under its current claim locks. Preserve BOTH independent Operation updating guards on rebase.

## Operation claims and seals (slice 19)

`DomainOperations` composes `OperationClaims`, an unregistered trusted `OperationPreflight` and
an admitted writer. Only synthetic writers exist. Registration is the initial supported financial
route; transfer/renewal/complimentary/management authority remains closed for 23–30. Preflight20/21
must compose contacts18 and fresh ordinary-name/term/capability/cost/balance policy; the readiness
DTO itself is evidence only. No production preflight, write bridge, job boot or scheduler is installed.

Claims and seals each lock native owner, complete invoice/items/payments/services/products/plans/
cancellations, server/account/credentials, domain then all operations by ID. Current locked funding,
owner/native annual route, frozen account/generation, reservation, lifecycle and unresolved outcomes
are revalidated. No current seller or new-sale flag is required. Known dispatch cost is independent
of billed retail: no margin floor. Locked server mutex serializes pause/rotation against the seal.
All provider reads and sends run at transaction level zero; outer transaction callers refuse.

One random claim token and request digest identify the worker. A seal commits before the writer;
its cost, funding and desired-state digest are frozen. Write-once attempt keys preserve absent
execution versus recorded null. A live descriptor/context must be consumed once by the future
bridge immediately before its named send; copied/forged/reused descriptors grant no authority.
Only the facade opens that in-memory scope after sealing. Bridges20 must enforce this consumption,
accepted account/site, fixed origin, deadlines/budgets and default-off feature gates themselves.

Ten 25-second requests plus 50 seconds yield a 300-second worker timeout and a 360-second claim
lease. Final seal requires a complete send timeout plus 60-second cleanup margin, current preflight
and unchanged payload. Jobs try once and fail on timeout. Active duplicate delivery does nothing;
expired claims become uncertain even when unsealed, and never regain dispatch authority. Pre-seal
exceptions hold definitely-unsealed work; post-seal exceptions record null execution and uncertainty.
Every response, including ack or rejection, awaits 21/22 action-specific proof; no automatic replay,
refund, fallback, completion or reservation release exists. A late worker cannot overwrite a changed
claim. For the same sealed worker, write-once late facts survive expiry and stale quarantine without
changing uncertainty or reopening authority. Reconciliation22 interprets these facts and correlates charges.
MariaDB paired races, current reads/EXPLAIN, cancellation writers acquiring the service prefix,
queue timeout versus retry_after and installed native guards remain 39/44 acceptance gates.

The combined18/19 claim authority calls ContactSetup::requireReady at claim and seal with the
already locked domain relation. A contact refusal before claiming schedules ContactRecovery::hold
only after commit; recovery reacquires its own ordered prefix and current funding. No claim/seal/
grant is created by that refusal. A postclaim contact change instead holds the attempted operation
as seal_authority_invalid: its retained claim prevents contact readmission, even without a seal.
ContactRecovery refuses finance claim/seal/grant funding keys as well as claim token, request digest,
started/lease and any unknown attempt/execution key. Live dispatch grants stay in memory only.
Both Operation updating hooks remain active. Contact holds release only in the internal recovery
scope; contact_disposition is write-once and contact_readmissions stays a bounded append-only
policy prefix. Later policy writers must merge and preserve both audit keys. No finance migration
is needed; contact profile migration000003 retains its unique basename.

## Slice20 admitted bridge boundary

No schema change. Internal registration bridges consume the exact live engine grant, re-lock the
slice19 financial prefix and recheck the durable seal, current ownership/funding/account/reservation,
generation, contacts and absence of unresolved work. They return only the locked frozen payload to
a protected named synthetic wire method after commit. DTOs, clones, contexts and durable receipts confer
no write authority; read transports still cannot dispatch purchase mappings. All feature gates default
off. Fresh preflight reads run outside locks; local acceptance is checked again at seal and consume.
USD/batch acceptance and safe annual renewal must be explicit. NameSilo durable fresh contactAdd plus non-default
readback proof for the frozen owner/account/payload is mandatory and unavailable by default;
its local proof check never performs provider I/O under locks.
Before claim, account rate slots are nonblocking and shared-cache dependent; no lock spans I/O. No real purchase,
completion, renewal, retry, refund, profile creation or installed wiring is supplied (21–30/39).
The writer's local acceptance gate always runs before seal, independent of the selected preflight.
Consume refuses any change to the sealed encrypted attempt/funding/cost/policy maps, including
new null-valued evidence. Disabled writes still quarantine expired running claims without I/O.
Seal also rejects encrypted evidence changes since claim and independently forbids prior charge,
refund and candidate facts by presence. The final transport entry consumes live engine authority;
only its protected named wire method receives the fixed origin, timeout and frozen payload.

## Slice21 registration acceptance and correction work

No migration. Immediate registration facts live in encrypted attempt evidence; registration and
corrections are write-once audit keys. Accepted purchases become pending, retaining reservation,
native paid invoice and registration_pending domain until22 proves unique charge/term/ownership.
No cost floor, refund, replay, seller fallback or expiry/billing anchor follows acknowledgement.
Correction entries require separate authorization and readback; their execution belongs to29–31/33.
Enom purchases on default DNS then attempts NS/readback. NameSilo 300/301/302 are purchases;
readback/removal failures retain acceptance and auto_renew/contact/NS/privacy correction work.
Unknown responses remain uncertain, including provider refusals lacking correlated no-charge proof.

Fresh NameSilo contactAdd/contactList work runs outside financial locks between two complete
claim-authority checks. Immutable encrypted profile details bind proof to owner/account/operation,
payload digest and 120-second freshness. A worker-local handle selects only its newly created
profile; restarts create fresh proof, never adopt inventory. Default or mismatched profiles refuse.
Production protocol paths require the bound bridge, real RegistrationPreflight and identical
per-account gate objects. Every concrete send first consumes base-private in-flight authority.
Ports have no default network exchange or installed composition. Account identity/USD/batch/live
acceptance, installed shared gates/cache, queues, paired MariaDB races and rate-limit release
remain39; unknown/late evidence interpretation and unique charge acceptance remain22.

NameSilo protocol composition also requires the same profile service in acceptance, preflight and
transport before claim. Ordinary checkout lacks desired NS/privacy capture:32/33 must capture and
freeze these before installed activation39; missing NS holds before profile work. Installed39 must
allow only RegistrationBridge + RegistrationProtocol writers; direct transport seams are synthetic.
Completion retries retain the original result; changed authority retains acceptance/corrections as
uncertain. Slice22 selects financial_completion=awaiting_unique_charge even with correction safe codes.
Correction progress uses separate append-only records; the original requirements never change.

## Slice22 original-attempt reconciliation

`OperationReconciliation::run` selects pending `registration.financial_completion=awaiting_unique_charge`
regardless of correction safe codes, uncertain attempts, or expired running leases. Each poll first
retains an encrypted staff-alert marker (notification delivery38/staff workflow36);
Only completed, covered reads with unchanged authority consume the five-poll budget; missing acceptance,
lag, failed/incomplete reads and stale snapshots do not. A distinct poll_limit_reached alert and bounded
append-only internal resume audit permit later staff-triggered reads; staff authorization/UI stays36.
No retries of purchase, refund, seller fallback or rejection follow observation. Live leases refuse.
The existing user/native-funding/server/account/settings/domain/ordered-operation lock prefix captures
and rechecks lifecycle, binding, generation, all operation evidence and acceptance around bounded
provider reads outside transactions (25 reads/250 seconds). Changed authority or cancelled/reversed
native funding prevents completion. Pausing spending permits account-scoped reconciliation reads.

`PersistedExecution` replaces Enom protocol execution objects; original operation/query/account/order
identity and frozen start/fingerprint bind retained acceptance, including matching late-worker facts. Its time window begins at the durable dispatch seal.
Absent and recorded-null executions remain distinct. Sealed attempts with neither can resolve only
through an explicitly accepted complete history contract, never by inventing an execution result.
Encrypted server setting `domains_history_acceptance` binds USD, all-pages completeness, exact
registrar registration vocabulary, explicit timezone, lag and retention. Absence is unaccepted;
unknown/paged envelopes, unsupported detail shapes, missing/ambiguous timestamps, exhausted budgets,
zero or multiple matches and out-of-window rows stay unresolved. Acceptance is an internal seam;
real account/provider contract acceptance and staff authorization remain39/36. Enom discovery accepts
only the opt-in flat OrderList/OrderCount shape; unaccepted provider variants remain unresolved.

A unique successful exact domain/action/term order with USD charge and precise original-window time
must also have current owned, full-term expiry (source two-day calendar tolerance, exact retained expected expiry). Enom captured IDs use dedicated expiry
plus corroborating exact order expiry; NameSilo absent IDs require accepted complete history after lag
and within retention. Only then do immutable `attempt.reconciliation` and `cost.actual` evidence set
succeeded/active, confirmed expiry/revision and `renewal_policy.billing_anchor`; native annual service
expiry stays null. Corrections remain separately authorized, immutable original requirements; observed provider renewal drift is separate correction work and cannot erase unique financial proof. Changed
actual cost/below-cost realized margin alert staff without undoing an incurred purchase or adding a floor.
Publication still holds below-cost registration/transfer and bills below-cost renewals with alerts.

`OperationTerminalProof::encode` normalizes only a completed, held, definitely-unsealed local worker
with no send/result/charge/candidate/refund facts. Expired claims, seals and present-null results never
become unsent. Reservation release remains solely in `DomainReservations`; no sent/provider rejection
or no-charge proof is synthesized from history. Refund-once26, renewal23–25, transfers27–28, installed
queue/scheduler acceptance39 and shared refresh/correction execution31 remain separate.

### Held-domain renewal admission (slice23)

`RenewalAdmission::check` accepts an expected domain/generation/confirmed revision/provider ID/expiry
and exact term. Its own private ordered native-owner/service/product/plan/cancellation/property/product-settings/server/
account/settings/TLD/prices/domain/operations prefix validates current holding authority and slice22's
persisted original-attempt/completion proof. It never calls the generic trusted-writer lock seam.
A fresh bounded read window (10 requests/250 seconds, quote lifetime at most 120 seconds) runs outside
transactions; then the prefix retakes locks and rejects changed anchors, authority or settings,
including same-account credential rotation. Seller and new-sale enable flags cannot strand holders.

Only proven ordinary or explicitly accepted one-year grace states with matching owned saved ID/expiry,
provider auto-renew off, nonpremium exact USD term cost and sufficient USD balance can pass.
Explicit Enom restoration inventory is a typed refusal; unknown states and NameSilo's unproven
restore-safe renewal path remain closed. Account USD/batch/rate acceptance defaults closed and shares
slice20's nonblocking account slot. Below-cost renewal produces a staff alert, never a retail floor.
Neutral typed decisions are server-only prospective facts: they create no obligation, funding/dispatch
grant, invoice, operation or expiry change. Billing24 and paid/complimentary authority, caps and
execution25 must revalidate independently; transfer-completion anchors extend the proof seam in28.

Before composing billing24/execution25, extend `RenewalRequest` with the expected obligation key.
Exempt exactly that matching unsealed obligation from sibling refusal, and accept the latest proven
completed renewal anchor; registration-only proof and register-only terminal safety are insufficient
for repeated renewals. Conversion41 must supply independently accepted converted-holding anchors.
Invoice24 locks belong between owner and service, matching `InvoiceFunding`; obligations fix the
term (including accepted additional years) independently of the annual native plan.
Admission reserves the shared account slot once for that attempt: execution25 must carry that
reservation through its final rechecks, never reserve again for the same immediate send. A separate
attempt needs a new slot. Constructor account/grace acceptance is internal only; wiring39 must read
it from locked binding-scoped settings and recheck it at the end of the window.
Immutable completed-history proof reconstruction currently fails closed if current history acceptance
is malformed/foreign; holder-safe recovery/acceptance tooling remains37/39. Neutral authority/read
codes also cover transient database/processing and mismatched-result failures; retry policy31 and
staff diagnostics35 must distinguish these without exposing exceptions. The retained retail comparison
is an advisory margin alert only; billing24 must use the validated holding renewal-price API (USD,
positive retail, term-total unit) and emit a staff alert when retail/margin is unknown.


Slice23b narrows NameSilo's refusal above: ordinary annual renewal may pass exact account-scoped
`Active`, date-only expiry at least two UTC calendar days ahead and an exact accepted account quote.
Validity ends when that UTC margin ceases. Grace/redemption remain unknown. NameSilo request and
snapshot provider domain IDs must both be null. Enom saved-ID behavior is unchanged.
Sealing now retains write-once encrypted `attempt.registration_admission` (premium=false, exact name,
term and authorized cost) after DispatchReadiness validation. No schema changes. A uniquely completed
registration anchor whose actual USD charge equals the authorized cost (normalized decimals) must match this proof before NameSilo's premium gate
can pass. Older holdings without that evidence retain Cost refusal; no flag is backfilled from
Active or a catalogue price. Slice28 must establish independent nonpremium proof for transfer-ins.
All23 auto-renew, balance, authority/generation, account acceptance and margin checks still apply.
The model permits adding this proof only in the original absent-to-sealed registration update.
Execution25 must honour validUntil and repeat Active plus the two-day margin immediately before send;
wiring39 must independently confirm restores disabled in API Manager. Acceptance tooling37/39 must
recheck per-TLD renewal-safe acceptance, including revocation, beyond today's account-wide quote acceptance.
Only annual NameSilo costs are accepted; other terms currently refuse State rather than Term.
When24/25 advance the anchor to a completed renewal, retain or resolve the original registration's
nonpremium proof; never backfill registration_admission onto renewal operation rows.

## Renewal invoices and native lifecycle (slice24)

`DomainBilling::invoice` owns a transaction. `HoldingLocks` takes the owner, all current owner
invoices/items/payments, every referenced service/product/plan, cancellations/configs/properties/
product markers/links, server/account/settings, TLD/prices, domain and ordered operations. Native
invoice locks precede services, matching InvoiceFunding. All billing price/rule reads are current
locking reads, including within an older native cron read view. MariaDB paired-connection tests
and EXPLAIN remain39: verify owner-invoice range locks, mixed-service ordering, concurrent native
cancellation/config writers and publication; SQLite establishes sequential behavior only.

Scheduled `domains_billing_enabled` and owner-requested `domains_manual_renewals_enabled` default
false and are independent. `domains_issue_days`/`domains_due_days` default21/7; new scheduled debts
require issue <= today < due, manual debts may issue earlier but never at/after due. Existing
obligations retain their term/calendar/amount through price, policy, date and gate changes. A unique
`renew:{domain}:g{generation}:{expiry}` key arbitrates the cycle under the domain lock. Obligations
freeze exact years, base/expected expiry, native route, confirmed revision and holder price evidence.
No provider admission is performed to bill. Non-selling holders still bill; account processing and
automatic spending pauses refuse new debt. Existing obligations remain frozen. Billing uses validated positive USD term-total RenewalPrice retail; unknown/stale costs and rule failures retain
published retail with an encrypted staff-alert marker. Below-cost renewal bills without a floor.

`NativeDomainGuard::boot` is dormant until39. Install once before CartAdmission/InvoiceBinding;
it binds ManagedPaidInvoice so native payment processing skips managed Service items, while other
native items retain upstream behavior. Renewal items have null native references. Payment grants no
activation, expiry, claim or execution: provider-confirmed completion remains22/25. Initial checkout
uses a narrow validated annual transition; future projections use the locked holding annual retail
plus native exclusive tax once. Coupon associations remain usage accounting, never renewal pricing.
Dirty native price saves require an outer transaction and fresh holder retail; stale forms, zero,
retained-only evidence, acquisition recalc, native configs and binding/annual mapping changes refuse.
Lifecycle-only status saves proceed without current price evidence and preserve null native expiry.
Ordinary price_mode/billing_managed edits cannot create complimentary authority; audited transitions
and complimentary cycles/caps remain25/35. Issued bound items and their invoice routing/calendar are
immutable. Privileged SQL/quiet/bulk edits remain outside these model-event guards.

Slice25 must compose action-specific paid funding (renewal items intentionally have null references),
expected-obligation-key admission, latest completed-renewal anchors, fixed terms/caps, stale/late paid
obligations/preferences, overrun pause and provider execution/completion. No operation dispatch, credit
auto-use, refund, reminders, notification delivery, scheduler, public routes or extension entry ships
here.26 owns financial disposition;27–28 transfer anchors;39 installed single-authority hooks, native
queue transaction behavior and MariaDB acceptance;41 independently proven converted holding anchors.

Billing reuses the full sealed registration-completion proof, not a succeeded state label. Historical
completed-renewal/transfer anchors remain25/28. Tax selection mirrors native Settings::tax using
locked owner properties and tax rows. Parent invoice deletion is refused before native cascade;
renewal base/expected expiry are frozen with the term. Native service/product/annual-plan deletion refuses.
The native panel currently defaults database transactions off: dirty price edits fail closed without
an outer transaction.39 must wrap the installed admin save before taking the ordered prefix. The
slice24 regression executes the real recalc action and persistence method inside that transaction;
it does not establish mounted-panel/browser acceptance or change native panel configuration.

User/order deletion also refuses before native cascades can erase holding records. Renewal invoice
membership is frozen after issuance, including null-reference additions and reparented adjustments.
HolderPricing::assertSave delegates to NativeDomainGuard's single locked, tax-aware save authority.
The positive dirty-price regression changes9.99 to fresh gross11.44; it is not an unchanged-price save.
Native InvoiceTransaction edit/delete authority remains25/26/35/39: execution must reacquire signed
current payments and funding, and installed staff financial mutations need explicit audited policy.
DomainBilling's prior-anchor checks must be extended in25 for completed renewal cycles. Latest
observation alerts are health summaries; immutable operation policy.price retains each cycle's alert
and retained flag. Delivery38 must consume operation evidence rather than the latest summary alone.
Installed39 must also prove no caller already holds later rows before taking HoldingLocks, and
EXPLAIN the product_upgrades OR query. Shared tax-row exclusive locking is safe but may serialize.

Cancelled unpaid renewal invoices refuse further billing for that cycle until25/26 implements a
verified terminal unpaid disposition plus a replacement-key suffix, preserving the original unique
expiry/generation obligation and all issued evidence (Enom b561b9f renewal_cancelled_unpaid parity).
Slice25 should consolidate RenewalAuthority's duplicated route checks onto the ordered HoldingLocks
prefix.39 must bind NativeDomainGuard as a singleton; boot-once is per instance. Calendar cutoffs
refuse new overdue debt even if retained domain status says expired/grace. Scheduled callers default
to one year; an explicit fixed paid term is internal policy input. Enom's actor-only multi-year rule
is in complimentary(), whose distinct audited cycle/term authority remains25, not paid invoicing.

Holding order ownership cannot be reparented; user deletion also checks indirect holding orders,
including already-drifted historical parents, before native cascades. Service saves resolve the
original retained service ID as well as the proposed ID, preventing primary-key edits from escaping
the binding guard. Bound invoice-item protection likewise checks original and proposed item IDs.
Deletion guards check both original and proposed native IDs: Eloquent deletes using the original
primary key even when a caller changes the in-memory ID before delete(). No key spoof bypass is allowed.

## Renewal execution and completion (slice25)

RenewalAuthority now shares HoldingLocks, including signed current payments. The request names
exactly its virgin obligation; all other unresolved work refuses. Paid null-reference renewal
lines require the current full native invoice and net succeeded transactions, never paid status
alone. Bound InvoiceTransaction financial edits/deletes refuse; native callbacks may update status/fees without changing signed fields.
Native full account credit can settle newly issued debt through ExtensionHelper::addPayment.
Retiring a transaction-free, unsealed unpaid cycle cancels/retains its debt and records
renewal_cancelled_unpaid; a distinct :replacement:N key preserves each original obligation.
Any later financial activity on retired debt prevents replacement execution.

RenewalCycles authorizes complimentary terms with fresh staff manage/fund permission, reason,
exact expiry/generation/years and explicit USD cap. A cycle gift preserves native price/mode.
Zero service price alone grants nothing; expired preflight leases may retry before sealing.
RenewalExecution reserves one account slot, repeats complete fresh admission, checks frozen cap
and current native authority, then commits one seal before opening an exact-object, single-use
RenewalWriter grant. Final consume repeats local acceptance/funding/binding/deadline checks.
Named Extend/UpdateExpiredDomains or annual renewDomain ports have no default network sender.
Below-cost retail never holds dispatch; fresh cost over the fixed obligation cap pauses spending
and retains a staff alert. Typed transient admission refusals stay queued; paid unsendable debts retain net-funding
refund candidates for26. Exact-live-grant prewire refusal records definitely_unsent; no refund occurs.
Issued paid obligations survive renew preferences, tariff and calendar changes; service cancellation requires review.
Credit auto-use requires server `credits_auto_use` (default off) and native `settings.credits_auto_use`; both must be true. Unknown costs refuse.

Unpaid pending invoices remain payable after an early execution check. Acknowledgements become pending.
RenewalCompletion counts at most five covered original-attempt polls (25 reads/250 seconds each), requiring one exact USD order charge/time/term plus current
owned full-term provider expiry and matching provider ID. No-ID outcomes require separately
accepted renewal history vocabulary/timezone/lag/retention. Completion advances the billing anchor
and revision; native expiry stays null. Original registration nonpremium proof stays on registration;
actual charge differences retain Cost refusal before future billing or admission. An incurred overrun
completes evidence and pauses future spending. Revocation after send cannot erase that outcome.
Expired running attempts quarantine without replay, including when writes are disabled.

These are internal, default-off seams.26 owns refunds;27/28 transfer anchors;31/35 scheduling,
held readmission and audited policy workflows;36/37 staff acceptance/history recovery;38 delivery;
39 installed singleton hooks, fresh binding-scoped policy, restores-disabled acceptance, native
financial transition integration, queue/deadline/shared-cache and paired MariaDB lock acceptance.

Slice26 `Refunds` locks the owner, USD credit, native ledger and holding before interpreting
unsent proof and the exact issued positive line. The whole invoice needs positive net succeeded
native funding; processing/failed payments and status labels grant nothing. Only exact typed25
prewire refusals can override a sealed renewal's possibly-sent marker. Unknown/fallback facts,
even null-valued keys, refuse credit. Refunding retains invoice/payment history and reservations.
Held disposition records fresh domain-manage + invoice-update rights, bounded reason and actor;
contact holds use their recovery seam. The rejected decision survives credit failure. Credit and
immutable funding.refund commit together, once; bounded retries recheck current evidence.
FinancialAdjustments appends signed native transactions and bounded immutable audits under the
same locks plus native transaction-create rights; cumulative corrections cannot add net funds.
Original payments stay immutable. Installed native action routing remains35/39.
Unsuitable paid renewals and late payments to retired cancelled debt use the same proof. Late
retired payments unblock replacement only when an exact full refund still covers every retained
transaction; later activity blocks again. Notifications38, scheduling31 and MariaDB races39 remain.
Refunded unsuitable paid renewals still own their cycle; safe replacement authority remains35/36.
Correction-only history remains unattempted; current native rows are rechecked and seals retain audits. See [adoption and invoice-history storage](storage-adoptions.md).
