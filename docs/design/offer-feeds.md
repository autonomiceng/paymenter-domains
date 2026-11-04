# Offer feeds

`Registrars/Enom/OfferFeed` and `Registrars/NameSilo/OfferFeed` implement B1's `Feed` via
`PricedFeed`; `priceReads()` supplies the bounded physical read count without registrar branching.
The shared account worker invokes them through the checked catalogue routing.

Enom reads reseller pricing for Years 1/2/5/10 and the suffix list once. Each requested bucket
is an evidenced exact term by default. Additional terms require an explicit bucket-to-term map
from provider evidence; a floor-bucket algorithm alone cannot prove them. Only registration uses
multiyear buckets; renewal/transfer remain annual. Annual input becomes an integer-calculated
exact-term total. Retail fields are ignored. Both envelope and indexed/XML row currency reject
explicit non-USD; absent currency retains the existing Enom USD protocol convention.

Missing/malformed prices or flags produce no cost observation. Explicit false produces disabled,
never a free price. Failed bucket/list reads return no commands. A listed unpriced suffix supplies
an empty discovery command; it cannot erase costs or refresh facts. NameSilo uses one account
`getPrices` without the retail flag. A quarantined row is an empty observation, annual-only.

The annual bulk row proves one renewal/transfer year only when its reseller amount is positive
and that action is enabled. No missing/zero amount grants a term. Enom `facts()` combines a
completed bulk read with the two metadata reads when available; the writer also combines saved
bulk rows with later metadata, so separate worker turns and restarts behave identically. Cost
observations retain the annual proof's source and original dates. Metadata keeps its independent
30-day age, registration terms and unknown registry fields. Bulk prices never prove extra fields,
nameserver requirements or auto-renew-only safety. NameSilo already has annual protocol terms;
its independently bound TEST protocol gate stays required, and unverified facts stay closed.

The registrar account Overview shows “Prices stay current for”, in hours (default 48, range
24–168), using the neighbouring account row and Edit dialog. The setting uses the existing
configure permission, actor-bound revision ticket and encrypted audit. No environment override
exists. `Data/CatalogueFreshness` reads the setting; feeds and normalized bulk-price checks apply
the same policy to the original observation time. Metadata retains its independent lifetime.
The account feed remains daily, leaving room for one missed run at the default age.
Staff can record selling intent while metadata is unknown or expired; the offer stays closed.
Selected, enabled staff sales request facts after a staff edit and when the bounded account
rotation finds unknown/expired metadata. Discovery alone never queues the whole catalogue.
Turning sales off stops those automatic facts reads; original send caps and read-group budgets stay.
Native price rows survive unavailable offers; the evaluator and admission still refuse purchases.

Registration checkout automatically re-quotes stale daily prices for the exact name, term and
selected account. `AcquisitionOffers` applies the current staff pricing rule and rechecks the
ordinary sale guards with temporary order evidence; neither daily nor native list prices change.
An unchanged total continues on the same click. A changed total uses the existing cart review
notice and next-click confirmation. Unreadable, below-cost and over-ceiling quotes stay blocked.
Pending invoices recheck expired order quotes without changing frozen amounts or cost ceilings.

B5 owns queue start/generation, policy selection, daily allowance/headroom and context construction.
`AccountReadBudget::reserve` atomically reserves a complete local group with one shared account
lease; `FeedReadBudget` uses its slots without counting again and releases the lease in `finish()`.
Choose the existing provider's background restriction via `restricted()`; pass any additional limiter
for per-wire policy. Keep existing transport daily/spend reservations and account/key checks intact.
Use a context budget of `priceReads()` and deadline no later than the group lease (calls * 25 + 5
seconds, minimum 30). Always call `finish()` in `finally`; failed/unused attempts stay charged.
No daily ledger or NS2/NS3 transport seam changes here.

`FeedRun::prices` buffers all commands and calls the queue's binding/generation guard before and
after reads. An older overlapping run or rebound account must throw and expose no observations.
This is not persistence authority: B4/B5 must repeat that guard with revision/binding checks under
the writer's existing locks, then apply only newer observations and retain omitted good data.
