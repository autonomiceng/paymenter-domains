# NameSilo read adapter

Slice 13 adds a neutral reader over the existing bounded read-only client. Construct it through
`AccountBindings::reader` after a positively verified stable account identity. No production
verifier/registry entry ships: the public read contract does not prove such an identity, and
neither a key nor a label substitutes. Synthetic registry verifiers establish integration only.

`acceptedAnnualActions` defaults empty. Each enabled action requires independent account USD/
annual-term acceptance. `getPrices` omits `retail_prices` and hypothetical quantity discounts;
no multi-year quote is inferred. Prices::rate checks binding/query/action/term/currency, current
observation/freshness, the destination TLD and exact four-decimal annual division. Unknown cost throws; it never
constructs a null withdrawal. PriceCatalogue alone creates revisioned PriceSnapshots. Neutral
contracts/storage semantics are unchanged; no currency conversion or dispatch floor is added.
The quote lifetime is a local freshness horizon, not provider validity; slice 14 must own a
shared policy tied to refresh cadence. Unsupported/unverified reads are definitely unsent read
refusals, never evidence rejecting a historical purchase.

Each wire read claims the shared budget and shortens the transport timeout to the deadline.
The additive DeadlineTransport interface leaves standalone read calls intact and gives neutral
reads a mandatory bounded transport. PurchaseMapping still cannot reach either path.

Domain expiry keeps date-only precision with no timezone. Missing premium stays null. Account-
scoped domain info observes ownership but does not prove which purchase executed. No domain or
purchase order ID is invented. Ordinary renewal assessment requires exact `Active`, a date-only
expiry at least two UTC calendar days ahead and an accepted exact annual account quote. Grace,
restoration and near-expiry dates remain unknown. The engine additionally requires this holding's
unique completed registration with actual charge and retained nonpremium admission proof;
provider renewal reads do not claim premium=false. Transfer-in proof remains a slice28 gate.

Reconciliation returns only unresolved candidates: at most a seven-day attempt window padded by one UTC day on either end
for the unknown provider timezone, 20 order headers, 200 candidates and the shared per-wire budget. Strict synthetic description patterns
`<name> - registration|renewal|transfer` and exact years are recognized, not arbitrary prose.
Naive timestamps have no DateEvidence; a date-only value retains date precision. A Processed
line subtotal is a candidate amount, never the whole order total or confirmed actual charge;
credited/unknown-status lines have unknown charge. History may lag, be incomplete, or have unknown
timezone. Zero, one, many or truncated searches all remain unknown with no replay/refund authority.

Source scenarios: existing `tests/api-test.php` and synthetic-success.json cover the wire shapes
and absent purchase IDs; namesilo-read-test.php covers neutral facts/bounds/precision/uncertainty;
namesilo-pricing-test.php covers native binding and pricing persistence. Enom 0.5 frozen reference
is `444f31ce0bfd07216e5200544bffe863119d0e7d`; no Enom implementation is imported in this slice.

14 must add shared catalogue eligibility, accepted TLD/action/registry/renewal terms and freshness
policy. 18 owns contact/profile isolation and .us/fallback acceptance. 20 requires durable admitted
sealed/funded/owned writes, identity/routing acceptance and safe renewal controls. 22 owns unique
actual-charge correlation, historical date zones/statuses/completeness and lag acceptance. No
provider calls or production usability are established by offline tests.

Paid registration, transfer and renewal now require **Expected default contact email (NameSilo)**
in the native Domains server configuration. Each dispatch reads the account's default contact;
the email must match after trimming and ignoring case. The first accepted contact ID and email
are retained encrypted in that server's settings and must continue to match. This is a contact
continuity check, not provider account identity proof. A missing setting, unreadable inventory,
missing/ambiguous default or changed fingerprint holds the funded request before any paid call.
Staff can correct the key or expected email and release the hold for fresh checks. Editing the
expected email never replaces the retained fingerprint. After a deliberate profile change, staff
with domain management access use **Accept current NameSilo account** (account page, More menu).
It needs a reason and reads the default contact again on submit. That email must match Expected
default contact email. Only then does it replace the retained contact ID and email and add a
staff settings audit entry. Held requests stay held: review and release each one separately.
Availability and pricing reads remain independent of this guard.
