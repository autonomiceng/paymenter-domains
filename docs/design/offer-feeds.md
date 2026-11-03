# Dormant offer feeds

`Registrars/Enom/OfferFeed` and `Registrars/NameSilo/OfferFeed` implement B1's `Feed` via
`PricedFeed`; `priceReads()` supplies the bounded physical read count without registrar branching.
Nothing registers or invokes these adapters in the current catalogue, jobs, billing or staff UI.

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

Enom cold facts use two existing named reads. Facts have independent times and preserve unknowns;
registration terms come from TLD metadata. These reads do not prove annual renewal/transfer term
support, so those fact terms remain absent. The later facts policy must supply separately evidenced
annual support before publication. NameSilo's current reader has no independent TLD facts endpoint;
`facts()` refuses without a wire request. Prices never establish registry or renewal safety.

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
