# Dormant offer writer

`Catalogue\Writer` implements the B1 contract. It is not bound in the container, called by routes,
listeners or queues, or activated on installation. The checked caller cutover is separate work.
Provider adapters supply `FeedObserved`/`FactsObserved`; all callbacks are local and must never read providers.

The constructor requires a clock, projection, authorization callback and readiness callback. Authorization
runs before reading and again under write locks. It must throw for unauthorized commands or staff/holder
queries; public/locked contexts also need the caller's account/feature policy. No permissive default exists.
Holder authorization must establish ownership on the queried account, independently of the selected seller.
Readiness must check local account binding, feature/processing and deployment gates. These are integration
ports, not authorization granted by the catalogue itself.

`Edit` factories represent account defaults (including the validated pricing rule/card fee snapshot), offer
overrides/stops/pricing rule, explicit seller selection/deselection, retained renewal retail and local
republication. The expected three-part revision is mandatory. Defaults use an existing offer as their anchor;
only the defaults revision advances. Every existing offer immediately becomes publication-pending. A worker
can enumerate that account's offers and issue bounded per-offer republication commands, without provider reads.
There is no scheduler or automatic fan-out in this change. Override null pricing rules restore inheritance;
legacy per-offer pricing rules remain until explicitly replaced. A fee snapshot applies to fee-based rules.

Normal writes own the transaction and lock native category/products/plans/prices, sorted servers/accounts,
selection, sorted offers, then prices. Seller changes lock/project all account offers for that ending together;
they refuse groups over 500 accounts. Default edits take only the non-native suffix and never request native
locks. Checkout's `Locked` reader joins an outer transaction after the caller holds native checkout locks;
it takes server/account/selection/offer/price locks and compares every expected revision.

Omitted and older observations are no-ops. Equal-time contradictions fail the transaction. Future observations
are refused. A nonempty first observation can create an unselected candidate using current account defaults;
its expected offer revision is zero, while its defaults and selection revisions come from `readOffers` even
when the offer is absent. Explicit intent and verified facts still gate publication. Configured hidden endings
are loaded into every snapshot, closing new acquisition reads and projection while preserving holder renewal.
Facts retain separate provenance/age and never appear on cost rows. Exact terms are never
interpolated. Retail uses rational decimal integer arithmetic with existing annual rounding and card-fee rules,
including totals that do not divide into a four-decimal annual rate. Positive retained annual renewal retail
survives missing/disabled/stale or invalid new retail; valid current renewal pricing can update it. The explicit
retained-retail command seeds/corrects that fallback independently; use a pricing override for a lasting rule.

Offer prices, projection and encrypted existing staff audit commit together. Native mappings/plans remain
permanent; withdrawn acquisition prices disappear, while native services/invoices/operations are untouched.
Unchanged native rows skip updates. Cache invalidation and account/TLD-scoped cart withdrawal happen after
commit. This path never copies acceptance proofs or changes acceptance state.

The focused native SQLite test proves sequential stale-edit refusal and lock-prefix query ordering, not
MariaDB concurrent interleavings or installed caller authorization. Parent integration must rebase over
reviewed B1/B2, exercise database concurrency at cutover, supply authorization/readiness callbacks and add
the bounded local republication scheduler before activation.
