# NS17 renewal terms

Annual renewals keep their existing authority. Longer terms require current ordinary-family and term evidence,
current facts with `renew: [1]`, a fresh `renew:N` cost in `term_total` units, a frozen debt rule and a sufficient
saved cost ceiling. The registry horizon stays ten years. Grace and redemption remain separate.

## Registration retail

For every sold registration term, customer retail is the one-year registration sell price plus the current
annual renewal sell price × (years − 1). The shared retail calculation applies the saved rule and rounding to
each annual input first, in fixed and markup modes alike. Both annual observations must be current; a missing,
disabled or stale input cannot supply a longer public offer. Checkout refreshes stale inputs automatically and
fences the selected price and both annual source inputs. Registrar wholesale remains the independently
observed selected-term registration cost; below-cost admission still compares the complete customer total against that exact wholesale cost.
Native plan prices, search, cart and invoice admission share this total. Issued invoices and operation terms
remain frozen, and the registrar still receives one registration for the purchased number of years.

## Enom TEST approval (2026-11-05)

`TermSupport::EXACT_QUOTES` admits Enom's per-year renewal cost × years. `REVIEWED_EVIDENCE['enom']['com']`
approves the two-year ordinary TEST term through 2027-11-05. `OfferFeed` derives `renew:2` from bucket 1's annual
renewal cost, attaches the reviewed evidence and derives `renew: [1]` from its enabled annual observation.
It never uses a registration bucket to price renewal. Unknown, stale or disabled evidence closes new longer debt.
NameSilo remains annual. Live longer terms remain held with `live_proof_required` until NS18; neither reviewed TEST
facts nor an issued TEST rule grants Live authority.

The owner panel uses `DomainBilling::renewalChoices` normally: two years costs 2 × retained annual sell price;
complimentary stays $0. The invoice freezes the two-year cost ceiling and rule. The executor compares the fresh
GetExtendInfo/Check agreed annual price × years against that saved ceiling before sealing/sending. An actual Enom
multi-year charge above the authorized quote stays uncertain for staff and pauses spending, even under a larger
staff cap; it cannot advance the accepted renewal anchor. Issued invoices remain immutable.

## Demo prerequisites

No new switch or manual price/evidence insertion. Keep the existing TEST identity, TEST catalogue and TEST renewal
deployment gates on (`domains.enom_identity.environment = test`, `domains.enom_catalogue.enabled = true`,
`domains.enom_writes.renewal = true`), with the existing account renewal acceptance, processing and owner request
setting (`domains_manual_renewals_enabled`) enabled. Refresh the normal account catalogue **prices and facts** after
installing this revision, so `.com` stores the reviewed siblings plus fresh enabled `renew:1` and derived `renew:2`
observations. The holding needs an ordinary confirmed expiry with room for two years inside the ten-year horizon.
Within that scope, the customer renewal form offers 1 and 2 years without a code change. Existing issued annual
invoices retain their term; use a cycle with no unpaid/active renewal debt. Demo/provider proof is separate.

Only `renew:1` publishes retained annual retail. Scheduled renewals remain annual. Complimentary staff limits can
exceed the quote, but cannot authorize an unquoted send above the saved limit. All other suffixes and terms stay
held until reviewed evidence is added through the same gates.
