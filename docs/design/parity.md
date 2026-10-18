# Enom 0.5 → NameSilo parity decisions

Read 2026-10-17. Baseline: Enom `d2c55e2`, its README, ordering, catalogue/pricing and lifecycle
docs. This matrix describes proposed future behavior, not implemented features. Commands and
public source links are in the [API contract](api-contract.md); every provider row inherits its
explicit interactive/background routing. No billing, cart, UI, schema or jobs are ported here.

| Enom 0.5 capability | NameSilo command(s) | Documented? | Gap / proposed behavior |
| --- | --- | --- | --- |
| Public ordinary ASCII search | checkRegisterAvailability | yes | Offer after premium/term validation and rate/cache policy; IDN/premium/EAP disabled. |
| Transfer eligibility search | checkTransferAvailability | yes | Offer after ordinary-name proof; availability does not establish transfer success. |
| TLD costs and retail pricing modes | getPrices, availability | partial | Fixed/markup rules are local. Exact-term quotes, promotions, currency and price cap unproved; disable sale until validated. |
| Registration 1–10 years | registerDomain | yes | Offer allowed TLD/term pairs after contact/flag gates; retain 301/302 as purchases. |
| Scheduled/manual renewal | renewDomain, getDomainInfo | partial | Disable dispatch pending renewal flag/restore gates; never infer grace eligibility from expiry alone. |
| Grace and redemption | renewDomain | partial | Staff-only after explicit eligibility/charge evidence; ordinary renew must not restore. |
| Incoming transfer/create/status | transferDomain, checkTransferStatus | yes | Offer after status vocabulary/completion acceptance. No arbitrary multi-year transfer claim. |
| Transfer-code recovery and resubmit | transferUpdateChangeEPPCode, transferUpdateResendAdminEmail, transferUpdateResubmitToRegistry | partial | Staff-only initially; no blind replay or resubmit after unknown result. |
| Outgoing EPP delivery | retrieveAuthCode | yes | Offer email request with cooldown; administrative recipient differs from Enom registrant email. No displayed EPP. |
| Creation contacts and four roles | registerDomain/transferDomain, contactAdd, contactDomainAssociate | yes | Offer after reject-fallback setting/readback proof; association is asynchronous. |
| Contact edits | contactList, contactUpdate, contactDomainAssociate | yes | Offer customer-isolated profiles; shared profile mutation affects multiple domains. |
| Registry fields / .us nexus | inline usnc/usap and contact profile | partial | Offer .us only after eligibility/privacy acceptance; .ca/.eu/other unmapped requirements disabled. |
| Arbitrary customer nameservers | changeNameServers, getDomainInfo | yes | Offer with 2–13 valid hosts/readback; 301 cannot refund registration. |
| Child nameservers IPv4 | add/modify/delete/listRegisteredNameServers | partial | Staff-only until domain scope and multi-IP readback proved. |
| Child nameservers IPv6 | same | provider-confirmed unsupported | Refuse; registered nameservers support IPv4 only. |
| Registrar lock | domainLock/domainUnlock, getDomainInfo | yes | Offer; already-set code still needs readback. |
| Privacy / paid Enom ID Protect | addPrivacy/removePrivacy, getDomainInfo | partial | Free privacy where eligible; disable copied paid privacy invoice/renewal assumptions. |
| Registrar auto-renew off | removeAutoRenewal, getDomainInfo; purchase flags | partial | Required readback before Paymenter owns renewal; customer enable control disabled. |
| DNS record CRUD | dnsList/Add/Update/DeleteRecord | yes | Optional offer after capability proof, preserve TXT and new record IDs. |
| SRV | dnsAddRecord/dnsUpdateRecord | partial | Disable edit until priority mapping resolved; weight/port/target documented. |
| DNSSEC DS | dnsSecList/Add/DeleteRecord | yes/registry-specific | Optional offer for accepted DS-input TLDs; key-input-only TLDs disabled. |
| Balance/connection evidence | getAccountBalance | yes | Staff-only; no automatic deposits or card use. |
| Operation reconciliation/charge evidence | listOrders/orderDetails/getDomainInfo/checkTransferStatus | partial | Staff-only ambiguous cases. No idempotency key or authoritative correlation guarantee found. |
| Native checkout, mixed cart, services, invoices, verified funding | none; local Paymenter | local | Deferred; retain one renewal authority and funding/ownership checks. |
| Credits, refund-once, complimentary authority/caps | none; local Paymenter | local | Deferred; unknown and fallback outcomes never auto-refund. No gateway refund port. |
| Reminders, observation, queues and concurrency seals | provider reads via apibatch | local | Deferred; scheduler must not send uncertain writes again. |
| Filament/client ownership, CSRF, staff cost permissions | none; local Paymenter | local | Deferred; no raw provider bodies in public views. |
| Encrypted settings, account/site/environment binding, processing off | none; local Paymenter | local | Deferred; explicit environment required now, no extension entry point yet. |
| Native ZIP, migrations, retained history on uninstall | none; local Paymenter | local | Deferred; no installable package/compatibility claim. |

Provider confirmations and remaining gates: [NameSilo provider facts](namesilo-provider-facts.md).

## Numbered acceptance/support gates

1. **IPv4 glue:** accepted syntax, TLD coverage, domain scoping, multiple IPv4 addresses,
   replace semantics and valid JSON readback. Resolve duplicate `ip` keys in published sample.
2. **Correlation:** no idempotency key or maximum-price parameter exists (provider-confirmed).
   Prove outcome/charge after timeout, disambiguate repeated renewals and correlate order
   rows. Establish history lag, time zone, retention, pagination and complete status values.
3. **Rate limits:** apply the confirmed limits in the provider facts; failed-request suspension
   threshold and OTE batch support remain unconfirmed. No automatic retry of failed requests.
4. **Sandbox coverage:** OTE origin is confirmed; use .com/.net for consistent checks. Verify
   transfer transitions, contacts, fallback codes, restores, IPv4 glue, DS, receipts and errors.
5. **Contact fallback:** enable Reseller Manager reject-invalid-contact setting, confirm missing
   contact behavior, both-invalid precedence and post-acceptance contact failure observation.
6. **Renewals/restores:** disable API Manager implicit restoration; prove policy for grace versus
   redemption, final charges, and whether `auto_renew`/`private` are accepted on renewDomain.
   Until then renewal mapping is draft-only; all purchase mappings currently have no dispatch path.
7. **Registry requirements:** .us profile versus inline fields (including coexistence with contact_id), foreign-entity extra requirements,
   privacy eligibility, TLD term bounds; keep unmapped requirements out of offered catalogue.
8. **Transfer outcomes:** exhaustive statuses/terminal evidence, resend operation echo typo,
   completion/expiry behavior and whether retries can create a new charge.
9. **DNS/DS:** SRV priority parameter despite MX-only distance prose; update type changes,
   singleton/empty responses, accepted DS algorithms and key-input-only registries.
10. **Quotes:** USD/account pricing evidence, exact-term promotions, renewal/transfer pricing,
    availability premium flags, and a safe cost ceiling before any funded operation is offered.

Offline tests prove local interpretation only. No gate is closed by the existence of a command
or by synthetic success fixtures; no account, sandbox or real-domain acceptance has occurred.
