# Offer contract (B1)

`Domains/Catalogue` is dormant. Nothing registers it in the container or calls it from current sales,
billing, staff pages, provider drivers or jobs. B2 supplies conversion/schema; B3 adapters; B4 the writer;
B5 scheduling; B6 the checked caller cutover. Existing catalogue writers remain unchanged until then.

`Catalogue::apply(command, expectedRevision)` is the sole future persistence boundary. `Feed` returns
account/TLD-bound `FeedObserved` and `FactsObserved` commands outside locks. It does not write tables.
Costs are exact-term USD totals, normalized from an explicitly evidenced annual rate or term total.
A one-year rate alone never creates another term. Positive disabled observations carry `enabled=false`
and no amount. Omitted/malformed rows and failed reads never withdraw good observations; empty feed
commands do nothing. The writer must compare observation times, recheck binding and reject stale revisions.
Feed commands never refresh facts. Facts have their own source/time/freshness on the offer.

`Snapshot` is the future batch/locked loader result, containing typed defaults/overrides, current costs,
facts, published retail, retained annual retail, selected seller and revisions. `accountReady` means the
loader has checked the account's existing binding, processing and feature gates; its default is false.
The loader also supplies configured hidden endings. No account-wide acceptance list or copied price proof
is part of the new contract. A snapshot may have no costs yet; its empty feed describes no observations.

`Evaluator::evaluate` is pure and serves every context. Staff/public/locked share availability decisions;
only authorized staff gets cost and specific reasons. Unknown/stale facts, unsupported registry/DNS/renewal
families, missing/disabled/stale costs, staff stops, wrong sellers, pending publication and below-cost
acquisition stay unavailable. The cost ceiling compares evidenced intended terms and annual renewal with
an annual limit using exact integers; required annual renewal always counts, even if stopped. A renewal stop
also rejects registration/transfer with `renewal_stopped`. Explicit Sell does not bypass the ceiling.
Renewal retail may be below cost; holder-retail behavior is unchanged.

`Revision` carries offer/defaults/selection versions. Offer changes include costs, facts and overrides;
default changes include pricing/card fees. Publication must match all three versions. Locked queries must
supply matching expected versions per TLD. Query bindings prevent cross-account/environment fallback.
`readOffers(query, context)` authorizes first, batch loads (or reloads under the established admission locks)
and calls the same evaluator. It returns `Decision` values, never snapshots/provider observations.

Holder context is only for an authorized existing holding's account and annual renewal. It returns retained
positive annual retail despite sales stops, deselection, pending publication or missing/stale evidence,
with a warning; it never falls back to another account or creates acquisition authority. Retained retail
is not a registrar quote or spending grant. Fresh per-order/lifecycle quotes, ownership/funding/one-send
checks and historical invoice/operation snapshots remain separate and unchanged.


## C1 shared caller preparation

`CallerRouting::legacy(body)` selects only its supplied, unchanged legacy body. PriceCatalogue,
HolderPricing and SellerPolicies use this fixed seam; no active target, activation, container binding,
bootstrap or scheduler registration exists. B1's staged Catalogue packaging exception remains.

`CallerPorts` explicitly composes Writer and OfferReader with trusted authorization and a required local
feature/runtime gate. AccountBindings checks the batch's current account/processing/configuration rows;
the feature gate reuses existing local acceptance, never a price summary or provider factory. Neither
callback may perform provider I/O. Composition does not itself accept NameSilo runtime or Live spending.
C6 must provide the complete, checked deployment composition; C1 exposes no production opt-in.

`readBatch(queries, context, lock)` preserves query/ending order and caps the whole batch at 500 endings.
With native item/product/plan/price locks already held, it locks ALL servers by ID, ALL accounts by
server ID, then policies by ending, offers and prices by ID. Authorization precedes data loading and
is rechecked after the account prefix. Readiness runs before policy/offer locks. A locked call reloads
rows and applies the existing evaluator/expected revision tuple, rather than reusing display objects.
Lock mode is independent of business context: locked Holder still returns only the authorized holding
account's retained annual retail despite deselection, disabled public sales or absent/stale cost/facts.

`loadBatch` supplies server-only ReadOffer mappings from those same rows and decisions. PriceSnapshot
carries real price ID/revision, normalized exact-term total, original source/age/expiry. Observation
also preserves the original source unit; decoding never multiplies a normalized annual source twice.
RenewalPrice uses the retained annual retail, with nullable ID/revision when no price row exists; no ID
or observation timestamp is invented. Holder evidence cannot produce a current PriceSnapshot. JSON
and debug output contain only the redacted Decision; PHP serialization refuses private evidence.
C1 introduces no cart receipt or posted SaleSelection fields: generation, credential and complete
revision-bound receipts belong to C2 and cannot be minted by client JSON.

Offline SQLite regressions cover batch behavior and read order, not concurrent row blocking or InnoDB
snapshot semantics. The installed two-connection current-read check is deferred and required before
activation. Native invoices, money and frozen operation snapshots are outside this preparation.
