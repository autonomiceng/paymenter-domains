# Domains implementation plan

Decision date: 2026-10-17. One MIT Domains extension with permanent Enom and NameSilo drivers.
This plan replaces the earlier cost-floor and unified-retail proposals. Foundation work is offline;
provider acceptance and a usable extension remain future gates.

## Architecture and decisions

- `Domains/Domains.php`, namespace `Paymenter\Extensions\Servers\Domains`, initial release 0.1.0.
  One runtime ZIP contains `Domains/` and MIT license; no separate provider extension entries.
- `Contracts/` and `Data/` define the internal seam; `Registrars/Enom/` and `Registrars/NameSilo/`
  own protocols. Services own checkout, funding, operations, billing and presentation. Native
  Paymenter core, authentication, tables and themes remain upstream.
- Native servers bind immutable `(registrar, server ID, account identity, environment)` tuples.
  Credentials stay encrypted; rotation preserves identity. Require positive ownership evidence;
  a label or key hash is insufficient. Processing defaults off with explicit live-site binding.
- One `domains_domains` ledger with globally unique nullable canonical `reservation_name`, plus
  `domains_operations`. Freeze account/generation/attempt/funding evidence. Missing execution and
  sent-null differ. Unknown never authorizes replay, auto-refund or another registrar fallback.
- `domains_tlds` and `domains_prices` belong to registrar accounts: one price table per account,
  per TLD, `use_default_rule` selects either the account's shared default rule or that TLD's
  override, never both combined. This yields one exact price per account/TLD/action/term/currency;
  see [retained storage and pricing rules](storage.md). Build no unified retail mode.
- Keep Enom 0.5 margin behavior: a registration or transfer term priced below cost is held from
  sale with a staff alert; a below-cost renewal is still billed and only alerts staff. No floors at
  obligation creation or dispatch, no held shortfalls and no subsidy workflows.
  Keep per-term registration costs and annual renewal/transfer semantics unless a driver needs
  verified additional terms. Unknown cost is not zero. Margin policy does not remove premium,
  eligibility, funding, prior-outcome, complimentary-cap or ownership safeguards.
- A default seller per TLD, independent register/transfer flags and policy revision. Seller switches
  and checkout lock the same policy row; switches affect future bindings only. Existing unpaid,
  paid, uncertain and owned holdings retain their registrar. One global reservation spans accounts.
- Permanent hidden native products per account/TLD/acquisition action; never repoint servers.
  Annual service plans show holder renewal pricing with null native expiry. Confirmed registrar
  expiry/generation drives billing; issued invoices stay immutable. Guard manual native reprice/save.
- Neutral results preserve acceptance/discrepancy/pending/proven rejection/unsent/unknown,
  field-level unknown, exact money and expiry precision/provenance. Ack is not completion.
  Reconciliation is read-only and bounded. No generic spending command escape hatch.
- Existing NameSilo reads stay read-only; purchase mappings remain non-dispatchable. Later writes
  require immutable engine admission and a sealed claim. Retain Enom paid privacy/DNS/DS/glue;
  unsupported NameSilo capabilities remain disabled, with provider differences visible to the engine.
- ID-preserving one-time staging conversion retains native/extension IDs, invoices/credits,
  encrypted inputs, reservations, attempts, refunds and customized notifications. Test the exact
  accepted source schema, dry-run references and empty target. Stop all writers; no dual-write
  bridge or aliases. Stage DDL before cutover marker. Keep legacy history read-only. After new
  financial/provider actions, repair forward rather than restoring an obsolete database.

## Ordered slices and changed-line allowances

Budgets include runtime/tests/docs. Per commit: additions + deletions ≤1,500, changed lines per file
≤800, final files ≤800 physical lines. Record pure path-move volume separately; source imports count
as full additions. Split oversized slices before merging. Numbers denote local slices, not published PRs.

| PR | Budget | Scope / predecessors / evidence |
| --- | ---: | --- |
| 01 | 450 | **Mechanical only:** relocate existing NameSilo API into Domains registrar namespace; autoload/test/doc path updates. All existing N checks unchanged semantically; no spending or parser changes. |
| 02 | 1,000 | Port offline native fixture/runner/package-test support; pristine P guard, manifests/receipts and runtime-byte checks. Register all suite categories honestly. Depends 01. |
| 03 | 1,100 | Neutral contract/context/result/evidence/capability DTOs and fake drivers; unknown, discrepancy, precision, binding and no-generic-execute tests. Depends 01–02. |
| 04 | 950 | Neutral models, retained-data migrations, uniqueness/schema verification, encryption and reinstall fixtures. No native hooks. Depends 02–03. |
| 05 | 900 | One reservation service/terminal release rules and shared canonical-name validation; cross-registrar/action/owner duplicate tests and transaction rollback. Depends 04. |
| 06 | 1,000 | Registrar registry and immutable native-server/account/environment/live-site binding; first-save encryption and key-rotation guards. Depends 03–04. |
| 07 | 700 | Exact money/markup/rounding, reusable per-TLD rules, per-account retail and staff margin alerts. Unknown/tax/discount tests. Depends 03–04. |
| 08 | 1,000 | Per-term registration and annual renewal/transfer cost persistence/refresh, revisions and holder lookup; native reprice guard contract. Depends 06–07. |
| 09 | 950 | TLD default-seller/revision setting and atomic switch/admission lock protocol. Disabled/unsupported seller, stale policy and switch-back tests. Depends 05–08. |
| 10 | 1,200 | Import Enom bounded transport/result plumbing and synthetic protocol fixtures in small final driver files; redaction, malformed/unknown/timeouts. Depends 02–03,06. |
| 11 | 1,150 | Enom catalogue/domain/contact/capability read adapter; normalized ownership, expiry, provider flags, registry schema. Depends 10. |
| 12 | 1,000 | Enom exact quote/renewal assessment/order-charge adapter; retain source unit/date/status semantics and unknown-cost tests. Depends 07,10–11. |
| 13 | 900 | NameSilo read-foundation→neutral adapter; absence of order ID, null premium, account prices, date precision and bounded correlation candidates. No purchase transport. Depends 03,06–08. |
| 14 | 1,050 | Shared catalogue import/sync and publication eligibility, retaining non-selling holders' renewal data. Dual-driver catalogue and unsupported-term fixtures. Depends 08–09,11–13. |
| 15 | 1,100 | Native hidden products/plans/slugs/markers; permanent account/TLD/action identities and annual service plans. No shared server repoint; native publication tests. Depends 14. |
| 16 | 1,200 | Cart event adapter, checkout fields and admission; quantity/terms/positive net domain lines, mixed hosting/domain carts and stale direct links. Depends 05,09,15. |
| 17 | 1,250 | Synchronous transactional invoice-item/domain/operation binding; full-invoice net funding, frozen checkout and annual service transition. Duplicate-event, partial/reversed-payment and cross-account tests. Depends 06,16. |
| 18 | 1,200 | Contact/registry validation, owner-isolated NameSilo profiles and audited definitely-unsent recovery. Four-role/schema differences, 301/302 and .us disabled-gate tests. Depends 11,13,17. |
| 19 | 1,200 | Small operation facade, claims/seals/lease math, generation/funding rechecks and one-attempt jobs. Failure before/after seal, stale claim, no network under locks. Depends 03,05–06,17. |
| 20 | 1,150 | Engine-only admitted write bridges for both drivers; transport accepts no unadmitted purchase, batch origin/deadlines/read budgets, feature gates default off. Depends 10–13,18–19. |
| 21 | 1,250 | Dual registration dispatch/completion and durable ancillary correction. Enom NS follow-up; NameSilo 300/301/302 acceptance, auto-renew off/readback and no refund/replay. Depends 18–20. |
| 22 | 1,250 | Engine evidence/reconciliation and unique actual-charge acceptance; Enom IDs vs NameSilo no-ID/history lag, zero/multiple candidates and unknown-after-send. Depends 12–13,19–21. |
| 23 | 1,100 | Ordinary/grace/redemption renewal admission; costs, ownership, flags, refused restore and binding revalidation. Depends 08,12–13,19,22. |
| 24 | 1,050 | Renewal invoices/calendar, null native expiry, separate gates and service-price save guard. Below-cost alert; issued invoice immutable. Depends 08,17,23. |
| 25 | 1,250 | Paid/complimentary renewal execution/completion; one obligation per expiry/generation, fixed terms/caps, late/preference/stale paid obligations, overrun pause. Split scenario files. Depends 19,22–24. |
| 26 | 1,100 | Account-credit refund-once/retry and staff financial rights; unknown/sent/fallback cannot refund, exact eligible line and transaction evidence. Depends 17,22,25. |
| 27 | 1,150 | Transfer/RDAP precheck, encrypted code owner POST, paid cutoff/edit rules and definitely-unsent timeout credit. Carry narrow 404/TLS behavior. Depends 18–19,26. |
| 28 | 1,150 | Dual transfer dispatch, pending/terminal status and completion/refresh; external transfer-in only, not internal registrar migration. Missing status/charge/expiry stays unresolved. Depends 20,22,27. |
| 29 | 1,200 | Enom management adapter and engine capability actions, including existing paid privacy invoice/readback and DNS/DS/glue semantics. Preserve source regression cases; no NameSilo assumptions. Depends 18–19,22,26,28. |
| 30 | 1,150 | NameSilo NS/contacts/lock/free privacy/auto-renew-off and emailed auth-code action, with readback and profile isolation; unsupported advanced actions refused. Depends 18–19,22,28. |
| 31 | 1,000 | Shared observation/refresh/bounded account budgets and durable post-acquisition follow-up; observer never re-anchors authority. Both accounts active after seller changes. Depends 22–23,28–30. |
| 32 | 1,200 | Storefront/search/pricing controllers/routes/views using selected seller and neutral errors; auth/rate limits, long strings, cross-owner privacy and held states. Depends 15–18,31. |
| 33 | 1,300 | Owned domain/setup/code/management views and presentation DTO; capability-specific labels/contacts/privacy/EPP, CSRF/form-key and ownership checks. Split controller from creation. Depends 24–30,32. |
| 34 | 1,250 | Shared CSS/JS/input partials; NS/contact limits, Enom DNS/DS/glue forms, errors/cutoffs/accessibility, no secret/cost serialization. DOM and rendered narrow/long-name fixtures. Depends 32–33. |
| 35 | 1,100 | Staff domain list/view/facts/policies, assigned-registrar identity and ownership/cost permissions. Split large admin view support. Native Filament tests. Depends 24,29–33. |
| 36 | 1,200 | Staff operations/reconciliation/refund/disposition UI and audited reasons; unknown outcomes cannot become unsent by UI selection. Depends 22,26,35. |
| 37 | 900 | Staff TLD prices/rules, seller revisions and two-driver setup; margin/renewal-holder alerts and encrypted probes. Depends 09,14–15,35. |
| 38 | 1,100 | Neutral reminders/templates/account-fair schedules, customization retention, bounded catch-up, missing/held obligation alerts. Both registrars remain serviced. Depends 24–31,35–37. |
| 39 | 1,250 | Complete native entry/hook/job registration and retained-data installation guard; one boot/listener/nav/scheduler authority, processing default off, legacy active-writer refusal. Depends all engine/UI slices. |
| 40 | 300 | **Deletion only:** remove now-unreferenced NameSilo scaffold-only docs/runner artifacts, if any, after their neutral replacements pass. No Enom source/history deletion or behavioral edits. Depends 39. |
| 41 | 1,250 | One-time staging converter/dry run/ID map/audit/completion marker and synthetic retained-billing/unknown-operation/partial-failure tests; no runtime compatibility aliases. Depends 39–40. |
| 42 | 1,000 | Final deterministic runtime-only ZIP/manifest, native upload/reinstall/boot fixture and license/path/symlink/refusal checks; conversion tool excluded. Depends 39–41. |
| 43 | 1,300 | **Separate theme PR:** neutral presenter consumption across home/login/cart/checkout/service/dashboard/invoice/nav/palette/CSS; absent engine and both holder cases. Develop against DTO early, merge after 32–34,39. |
| 44 | 1,200 | Paired-driver end-to-end parity and repeated seller-switch scenarios; installed candidate/converter/theme coordination, race-test harness and release/rollback/support docs. Close source-scenario inventory gaps. Depends all. |

Follow-up after 28 (not in the totals below): **move to save**, 2–3 slices / ~3,000 lines. When a
held domain renews for less at another configured account at a different registrar, offer a
one-click move between our own configured accounts via a registry transfer that adds one year.
Offer from ~90 to 14 days before expiry, only past the transfer lock and where the cheaper
account sells the TLD; copy registrar-hosted DNS first. Same-registrar account moves are not
registry transfers, add no year and remain out of scope unless separately verified.
Customer wording never names a registrar.

Future stack: **44 slices / 47,450 lines**, down **1,000** from 48,450.
Changed allowances: 07 1,100→700; 08 1,250→1,000; 23 1,200→1,100;
24 1,250→1,050; 37 1,050→900. PR03 1,000→1,100 covers sealed-attempt/reference
and immutable evidence regression depth; floor removal saves 1,100 before this 100 allowance. Existing four client slices contribute 1,708 cumulative
lines: **48 slices / 49,158 lines** combined. Optional NameSilo DNS/glue/DS adds three slices /
3,600 lines: **51 / 52,758**. Enom release review is outside these totals. Preparatory plan docs
are charged to foundation allowance; estimates remain ±25%, and hard limits require extra commits.

## Parallel worktrees and owners

| Lane | Slices | Role / exclusive ownership | Start gate |
| --- | --- | --- | --- |
| Integration | 01–06,15–17,39,42 | Integration: schema/binding, entry/hooks/routes, suite manifest | Foundation |
| Enom | 10–12,29 | Enom driver: provider subtree and additive fixtures | 03,04,06 |
| NameSilo | 13,20,30 | NameSilo driver: provider subtree and additive fixtures | 03,04,06; writes wait 19/20 |
| Pricing | 07–09,14,37 | Pricing: cost/rule/margin semantics and seller revisions | 03,04,06 |
| Finance | 19,21–28,31,38 | Financial: claims/funding/completion/reservation release/lock order | Frozen schema/binding |
| Contacts | 18,27 inputs | Contact: validation/recovery/RDAP inputs | Driver schemas; refund gates for 27 |
| Presentation | 32–36,43 | Client/admin/theme: separate views/controllers/presenter consumer | Frozen DTO fixtures; merge after engine |
| Conversion | 41,44 conversion | Migration with integration/finance: offline converter | Accepted Enom schema,39/40 |

Assign individuals to these roles before parallel work. Central manifest/hook edits go through the
integration owner. Merge/rebase by listed dependencies. Freeze the actually accepted Enom 0.5
revision before importing behavior; record MIT provenance and a source-scenario→target-test map.

## Acceptance and unresolved decisions

- Every boundary runs all available offline suites; receipts disclose omitted/not-implemented suites.
  Native fixture checks do not prove Domains installation or production database concurrency.
- Retain both-holder renewals, repeated switches during checkout/payment/seal/uncertainty, same-name
  account/action/owner races, cancelled-service reservations, complimentary caps and native reprice tests.
- Verify conversion IDs/funding/secrets, package/reinstall/permissions, account-fair schedules and
  absence of old writers. MariaDB paired-connection races and installed UI/theme/ZIP acceptance
  need separately authorized disposable environments.
- Provider acceptance remains open: stable binding, routing/rates, term/premium pricing, contacts,
  auto-renew-off, restore refusal, order/time/charge correlation and transfer terminal evidence.
  NameSilo new sales require an accepted holding-registrar renewal path as well as registration.
- Confirm initial TLD/terms per account before staging.
  Test conversion feasibility against the final accepted schema before any staging cutover.
