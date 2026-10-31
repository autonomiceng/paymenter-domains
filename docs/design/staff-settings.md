# Staff settings reads and one-use reviews

TLD list cells share one server-only facts snapshot per TLD during the table render.
Fresh staff grants are resolved once for that render; neither grants nor snapshots are
Livewire properties or write authority. Price validation reuses the same pure evidence
validator as fresh catalogue reads. Writes still reload and lock current evidence.

`domains_staff_review_keys` retains the consumed random 128-bit ticket key under the
composite primary key `(server_id, review_key)`, with ticket expiry for future retention policy. The account/server mutex serializes
claims; the unique constraint is a second duplicate guard. A local failure rolls back
the claim and write together. I/O request phases commit consumption before leaving
the transaction; only the same verified review instance can perform its in-flight
recheck. Tickets still bind actor, account, TLD, action, expiry and current state.
Reinstallation retains consumed keys. This unreleased foundation declaration evolves
in place; there is no upgrade migration or reconstruction from encrypted archives.

The current encrypted native setting remains bounded by 64,000 bytes. Rotation moves
older batches into extension-owned `domains_audit_archives`, with encrypted payloads
and an index on `(server_id, family, id)`. Reminder schedule archives use the same
store under a separate family. Native extension calls load all server settings, so
archives must live outside that relation. Writes read only their current batch; audit
retention is independent of replay authority.
Stamps and non-audit settings locks exclude the current audit and legacy archive keys
in SQL. No archive conversion or silent repair of older development schemas runs.
Recent history reads the current batch and latest archive only, then displays at most
50 entries. TLD filtering applies within that recent account window, so it may show
fewer entries; older batches remain retained. Corrupt current history refuses writes;
corrupt older archives cannot block a safe change or force a full-history scan.

Narrowing terms or pausing existing sale flags requires no catalogue acceptance proof.
Widening bounds and restoring withdrawn sales still require explicit confirmation and
current acceptance/import evidence. Disabling renewal support remains forbidden while
retained holders depend on it.

## After expiry

There are no per-TLD grace or redemption settings. Late renewal and restore are
off for every account: staff and customers see **Expired** or **Redemption** with
one next step, to contact support. `RenewalAcceptance::$grace` is false in every
shipped build, so `RenewalAdmission` refuses a domain the registrar reports as
expired. Paid requests that reach it hold without sending or spending.
When late renewal is built, it asks the registrar about that domain at that moment
rather than reading saved days or fees.

Rows left by older versions under `domains_lifecycle.<tld>` are ignored. Their
audit history still shows on the TLD page as "Grace and redemption changed".

Do not use Enom's GetTLDDetails cancellation/refund GracePeriod as a post-expiry
window. Enom `RGP` maps to redemption; `Extended RGP` maps to a distinct
extended-redemption state for support handling. Both remain refused by ordinary
renewal admission. There is no restore writer.

## TLD prices

The TLD page **More** menu holds **Edit prices** and **Edit terms**. **Edit prices**
offers the account default or prices for that TLD only, shows the formula in words
and previews register, renew and transfer for each sold term (cost, current price,
price after saving, below-cost rows flagged). Saving publishes at once through the
same publish path and price revisions: below-cost registration and transfer terms
are held, below-cost renewals are still billed, both alert staff, and issued invoices
keep their prices. Missing or stale costs refuse the save and change nothing.

**Edit default pricing** lives on the registrar account page. Its dialog names the
TLDs that use the default; saving publishes each of them and reports any it could not
publish. Enom catalogue accounts, TEST and live, publish only when staff save; other
accounts also republish in the background after each price sync. Choosing the account
default in **Edit prices** runs the `remove_override` review; the `publish` review
stays server-side with no button. Because saving publishes, both dialogs also need the
publish permission.

## Card fee and Price list

**Card fee** sits beside default pricing on each account. It starts at 2.9% + $0.30
per payment. Changes need the card-fee and publish permissions and keep an audit row.
Only TLDs using **Add a percentage to cost and card fee** get new prices after a fee
change; other rules and published prices stay unchanged. Failed publications are named.

The fee mode finds the smallest cent price covering `(cost + card fee on that price)`
plus the markup, then rounds up as selected. It rounds each year's price before
multiplying by the years. Impossible percentages and overflow refuse the change.
Below-cost sales rules still compare with registrar cost alone.

**TLDs & pricing → Price list** requires the costs permission, including downloads.
It shows saved exact-term prices, never a recalculated or invented multi-year price,
with registrar costs and estimated profit (Est. profit) after the account's current
card fee. The fixed fee counts once per chosen term. Each TLD shows Default or Custom
pricing. Filters and CSV use the same matrix. Loss after fees is a separate flag from
below registrar cost. The explanation names the current rule;
a missed publication can leave a saved price on an earlier rule. Issued invoices never
change. These are estimates: international cards, refunds and shared payments differ.

## Registrar costs and renewal margin

Regular renewal uses published annual net retail from `PriceCatalogue::renewal`
through `HolderPricing` and `NativeDomainGuard::quote`. Publication can use
percentage markup, amount markup or fixed retail, including configured rounding.
Issued invoices retain their own prices. A configured markup alone therefore does
not describe the actual published renewal margin.
