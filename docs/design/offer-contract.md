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
an annual limit using exact integers; explicit Sell does not bypass it. Renewal retail may be below cost.

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
