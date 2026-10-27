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

## Post-expiry lifecycle facts

**TLDs & pricing → a TLD → Edit grace and redemption** records facts for
one registrar account and TLD. It requires view, policy, cost and lifecycle-setting
permissions, a current one-use review and a reason. Before/after facts and sources
stay in the encrypted staff audit. Viewing costs requires the cost permission.

Evidence lives in the native encrypted server setting `domains_lifecycle.<tld>`.
It carries the account fingerprint and exact ending. This reuses existing settings
locks, review stamps, retained storage and upgrade handling. There is no new table
or migration. Existing installations have no facts and therefore no late capability.
Duplicate, unencrypted, unreadable or differently bound settings refuse eligibility.

Staff records post-expiry grace days, restore days, support flags, auto-renew-only
status, conflicts, policy source and verification time. Extra grace charge and
restore fee each retain their own amount, currency, source and verification time.
Zero means independently verified no extra charge; absence never means zero.
Verification requires an explicit UTC offset. Opening or saving a form does not
refresh its verification time. Saved facts do not expire: they stay until staff
change them, and the TLD page shows when they were last saved. The policy and the
relevant charge must both be saved; a verification time in the future refuses.
Unset facts show as "Not set" and keep late renewals and restores off. Non-USD facts are retained but cannot enable the
current USD billing path. Conflicts, missing facts and zero/unsupported windows
close the affected capability. Auto-renew-only endings never allow grace renewal.
Grace admission starts at the provider expiry instant, ends strictly before the earlier provider or saved UTC expiry plus the verified whole grace days, and cannot remain valid beyond that deadline; unknown expiry instants refuse admission.
Do not use Enom's GetTLDDetails cancellation/refund GracePeriod as a post-expiry
window. Resolve account and registry policy conflicts before clearing the flag.

The capability check is local policy eligibility only. It grants no owner checkout,
quote, invoice, funding or provider write. Owner requests still stop at the expiry
calendar date. Restore additionally requires separate provider acceptance from
trusted deployment code; staff cannot enable it here. There is no restore writer.
Enom `RGP` maps to redemption; `Extended RGP` maps to a distinct extended-redemption
state for support handling. Both remain refused by ordinary renewal admission.

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
publish. Live accounts also republish in the background; TEST accounts publish only
when staff save. Choosing the account default in **Edit prices** runs the
`remove_override` review; the `publish` review stays server-side with no button.

## Registrar costs and renewal margin

Regular renewal uses published annual net retail from `PriceCatalogue::renewal`
through `HolderPricing` and `NativeDomainGuard::quote`. Publication can use
percentage markup, amount markup or fixed retail, including configured rounding.
Issued invoices retain their own prices. A configured markup alone therefore does
not describe the actual published renewal margin.

`LifecycleFacts::price` preserves that effective markup: total net price is
`(verified regular renewal cost + extra registrar cost) × annual net retail /
verified regular renewal cost`, rounded up once to cents. A verified zero grace
charge returns regular annual retail. Restore includes renewal plus the restore
fee. There is no extra service fee or separate margin setting. Unknown/zero renewal
cost, mismatched currencies and below-cost regular retail refuse late pricing;
ordinary below-cost renewal behavior remains unchanged. Future quote creation must
supply the holder's current annual net price and fresh verified renewal cost, then
apply native tax and freeze the accepted total. This helper creates no quote.

Later checkout must fully refund the owner when the registrar did not charge us.
An unknown charge needs reconciliation before a refund decision. Successful restores
are non-refundable. Quotes, consent, invoices, provider writes and refund execution
remain separate work.
