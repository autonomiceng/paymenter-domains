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
