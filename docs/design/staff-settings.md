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

There are no per-TLD grace or redemption settings. **Late renewal** is one account
switch, Off by default and separate from **Renewals**. TEST can issue a one-year
late invoice only after fresh owned-domain, exact expiry, expired-list status,
TLD reactivation/deletion facts and matching renewal costs pass. The native invoice
freezes fresh cost plus the regular dollar margin, cost ceiling and exact deadline.
Auto Pay follows existing consent and attempts once; an earlier ordinary debit
cannot become another scheduled attempt after expiry. No overdue write retries.
Live additionally requires normal activation and dated account-specific late-renewal
evidence; no evidence ships. See the release runbook for the supported reader contract.

Rows left by older versions under `domains_lifecycle.<tld>` are ignored. Their
audit history still shows on the TLD page as "Grace and redemption changed".
Cancellation/refund GracePeriod is never a post-expiry renewal window. The reader
uses fresh GetTLDDetails Renewal DeleteDay only with supported reactivation flags;
its expiry offset and fee semantics require confirmation before Live enablement.
`RGP` and `Extended RGP` remain distinct redemption states for support. Neither
ordinary nor late renewal admits them. There is no restore writer.

## TLD prices

**Edit prices** is the blue button at the top of the TLD page. Its **More** menu holds **Change registrar**,
**Open in What you sell**, where terms are bulk edited, and **Restore sales** when a sale is turned off. **Edit prices**
offers Default pricing or prices for that TLD only, shows the formula in words

and previews register, renew and transfer for each sold term (cost, current price,
price after saving, below-cost rows flagged). Saving publishes at once through the
same publish path and price revisions: below-cost registration and transfer terms
are held, below-cost renewals are still billed, both alert staff, and issued invoices
keep their prices. Missing or stale costs refuse the save and change nothing.

**Default pricing** sits on the What you sell header, the same in All and one-account
views, and holds the one default rule and card fee for the whole business. Saving writes
both in one transaction, then publishes account by account, each on its own: the result
names any account that failed and saving again republishes it. TLDs already on the rule
stay as they are. A disabled account reads the same rule, so publish skips it, the dialog
says so, and its prices catch up once it is back on. The review, stamp and history use
`StaffSettingsReview::ALL_ACCOUNTS`, and every account's history records the save. Enom catalogue accounts, TEST and live, publish only when staff save; other
accounts also republish in the background after each price sync. Choosing the account
default in **Edit prices** runs the `remove_override` review; the `publish` review
stays server-side with no button. Because saving publishes, both dialogs also need the
publish permission.

## Card fee and saved prices

**Card fee** is part of Default pricing, one for the whole business. It starts at 2.9% + $0.30
per payment. Saving needs the default rule, card-fee and publish permissions and keeps an
audit row on every account.
Only TLDs using **Add a percentage to cost and card fee** get new prices after a fee
change; other rules and published prices stay unchanged. Failed publications are named.

The fee mode finds the smallest cent price covering `(cost + card fee on that price)`
plus the markup, then rounds up as selected. It rounds each year's price before
multiplying by the years. Impossible percentages and overflow refuse the change.
Below-cost sales rules still compare with registrar cost alone.

**What you sell** shows costs, the years choice, the pricing panel and the CSV only with
the costs permission; without it staff get the page without them. Past 1-year Register
it shows saved exact-term prices, never a recalculated or invented multi-year price,
with registrar costs and estimated profit after the account's current card fee. Gray
1-year Register prices are estimates and stay out of the CSV. The fixed fee counts once
per chosen term. Each TLD shows Default or Custom pricing in the Pricing column. The Show
filter and CSV use the same matrix. All accounts is view only. `/price-list` redirects here. Loss after fees is a separate flag from
below registrar cost. The explanation names the current rule;
a missed publication can leave a saved price on an earlier rule. Issued invoices never
change. These are estimates: international cards, refunds and shared payments differ.

## Registrar costs and renewal margin

Regular renewal uses published annual net retail from `PriceCatalogue::renewal`
through `HolderPricing` and `NativeDomainGuard::quote`. Publication can use
percentage markup, amount markup or fixed retail, including configured rounding.
Issued invoices retain their own prices. A configured markup alone therefore does
not describe the actual published renewal margin.
