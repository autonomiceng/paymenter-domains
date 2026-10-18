# Catalogue quarantine

NameSilo may return a successful catalogue with an ending missing registration pricing. Accept
well-formed rows independently; retain an explicit malformed row as typed `CatalogueUnavailable`
evidence. Require all three positive exact decimal prices for a usable row. A missing ending or
failed read remains a read failure and retains prior evidence; it is not an explicit quarantine.
An entirely malformed catalogue or invalid ending key remains unknown.

For accepted endings, `CatalogueSync` imports valid rows and records quarantined endings separately
from read/persistence failures. A persisted quarantine advances the scheduler cursor so other
endings keep refreshing; actual failures still hold the account. Under the existing server/account/TLD/price lock order and acceptance revision
recheck, quarantine sets wholesale to null, clears acquisition retail and freshness, advances price
revisions, and disables registration/transfer. It also creates nullable rows for accepted terms
that have never been priced. Existing annual holder renewal retail and renewal configuration
remain intact. New purchases and current quote admission fail; retained holder billing alerts
staff that cost is unknown. Older observations cannot invalidate newer price observations.

Staff price evidence carries `price_unavailable=true`, `staff_alert=unknown_price`, and the label
**Price unavailable** in eligibility metadata. This records the status for the later catalogue
staff interface; it does not add that interface. Rule republication preserves quarantine until a
fresh valid catalogue row restores proof. No unknown amount becomes zero or a confirmed provider
withdrawal. Existing invoices and frozen obligations are unchanged.

This row-specific handling replaces whole-batch failure for malformed NameSilo price rows. It
does not relax money syntax, account binding, term acceptance, seller selection, margin policy,
renewal safety, or purchase evidence. A malformed sold ending still refuses quotation and sale;
the OTE write gate requires valid com/net prices even when other rows succeed.
