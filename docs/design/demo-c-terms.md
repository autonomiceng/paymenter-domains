# Demo C lane T: NS17 terms and renewal families

Backend only, on C0 `e1631bd4`. Zero SQL migrations. No producer ships reviewed evidence yet, so every
installed row keeps today's behaviour until a reviewed proof is added.

## Rules (`Catalogue/TermSupport`)

- Annual renewal and provider registration terms keep their existing authority. `Facts::terms` stays the
  evaluator's set; renewal and transfer stay `[1]` there.
- A longer renewal (2 to 10 years) is offered only when all of these hold, else it is held with a reason:
  first-pass suffix (`com`, `net`, `org`: `not_reviewed`), a registrar whose pre-send admission read quotes the
  exact multi-year total (`no_exact_quote`), current facts
  (`unknown_facts`, `stale_facts`, `unsupported_facts`), a current reviewed `ordinary` family (`family_unreviewed`)
  whose `horizon_years` fits (`horizon`), current `term_evidence.renew[years]` listed in the family
  (`term_unreviewed`), `term_total` units on both (`annual_unit`), and a fresh enabled exact `renew:years`
  cost observation in `term_total` units (`unknown_price`, `provider_disabled`, `stale_price`, `annual_unit`).
- **No registrar qualifies yet, so every longer renewal stays annual** (`EXACT_QUOTES = []`). Enom's pre-send
  quote is the GetExtendInfo/Check annual price × years (`Enom/Renewal.php`), and PE_GetProductPrice is a
  selected-term annual rate, so an exact-term cap would be checked against a guess before money moves. NameSilo
  getPrices has no years parameter. NS18 must add a reviewed exact-term renewal read (and Check/GetExtendInfo
  agreement) before a registrar is listed; the other rules are tested through an explicit stand-in list.
- Annual units never price a longer renewal: `Cost` refuses `renew` terms above 1 unless `term_total`.
  This replaces the guessed annual-wholesale-times-years cap.
- Years always stop at the registry horizon: new expiry no later than ten years from now (Feb 29 does not overflow).
- Grace stays held (`family_unreviewed`, or `live_proof_required` with reviewed evidence; paid proof is NS18).
  Redemption is separate (`restoration_request`). Existing Enom late renewal is unchanged.

## Storage

Reviewed evidence lives in `Facts::expansion` and is stored as the C0 siblings `term_evidence` and
`renewal_families` in `domains_tlds.catalogue_facts`. Facts without it encode byte for byte as before.
Producers attach it through `TermSupport::attach` (first-pass suffixes only): Enom `OfferFeed` takes an injected
`$expansion` map (default empty; C1 wires reviewed entries), NameSilo TEST reads the same keys from its dated
protocol row. A facts refresh without evidence drops it, closing new longer debt only.
Exact `renew:N` observations go through the existing writer; only `renew:1` feeds retained annual retail.

## Debt, admission and complimentary renewals

- `DomainBilling::invoice`: scheduled renewals are annual. A longer owner renewal on a catalogue holding needs an
  available term; its cap is the exact-term cost and `policy.renewal.term_rule` freezes family/term source,
  version, rule, validity, safe boundary, horizon, exact cost and new expiry. Retail stays retained annual retail
  times years. Annual debt and holdings without a catalogue offer keep their old shape. Issued debt is never retrofitted.
- `RenewalAdmission` admits a longer term on a renewal-enabled account only on a **TEST** binding, for a named debt
  whose frozen rule matches the term and whose cap covers the frozen cost (`TermSupport::admits`), and only for a
  registrar in `EXACT_QUOTES`. With the list empty, an already-issued multi-year debt is refused (`renewal_term_refused`). Live
  multi-year renewal stays refused until NS18. Provider reads still decide state,
  quote and boundary (NameSilo: Active, date at least two UTC days ahead).
- `RenewalCycles::complimentary` applies the same eligibility; the staff cap must cover the exact cost.

## For C1

- Real Enom facts carry no `renew` terms (`OfferFeed` reads renew only in bucket 1, as annual cost), so under the
  real producer every longer Enom term reads `unsupported_facts` before evidence is even checked. Longer Enom terms
  stay held until the producer yields `renew: [1]` facts, reviewed evidence, exact `renew:N` costs and an exact quote.
- No producer ships exact `renew:N` costs or reviewed term/family evidence; `OfferFeed`'s `$expansion` is empty.

## What the UI lane gets

- `DomainBilling::renewalChoices($domain)`: `terms`, `order_by`, `lines[years]` (`first_year`, `renewal`,
  `renewal_years`, `total`, `expiry`) and `next_annual`. `requestOptions` keeps its old keys.
- `RenewalCycles::complimentaryOptions($domainId, $staff)`: `terms[years]` (`expiry`, exact `cost`),
  `max_years` and `horizon` ("Up to N added years fit before ...").
- `Capabilities::termReadiness($binding, $tld)`: renewal years with reasons, plus family states for Edit terms
  and registrar setup.
- `TermSupport::lines($firstYear, $renewal, $years)` for search/cart totals (first year + renewal × (years − 1)),
  and `TermSupport::recheck($years, $was, $current)` for the cart `same`/`changed`/`removed`/`missing` cases.
