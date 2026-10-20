# Enom read driver

MIT adaptation from [autonomiceng/paymenter-enom](https://github.com/autonomiceng/paymenter-enom),
frozen commit `444f31ce0bfd07216e5200544bffe863119d0e7d`. Original copyright:
2026 Autonomic Engineering; license retained in `Domains/LICENSE`.

Slice 10 extracts `Enom/Api/RegistrarClient.php` and `RegistrarResult.php` into bounded,
read-only protocol files. A closed `ReadCommand` enum excludes every mutation. `Client` checks
its Enom UID against the supplied account binding and claims the neutral context before each
request. Acceptance and live-site guards belong to `AccountBindings::reader`; the internal constructor
does not verify ownership. Production verifier/registration remains gated.
Transport uses fixed test/live HTTPS POST, no redirects/proxy/retries, 8s connect / 25s total caps
shortened by the context's engine-selected clock deadline, 1 MiB responses and 8 MiB TLD catalogues. XML depth/node
limits apply to injected responses too. Read failures cannot reject a historical operation.
Raw replies/credentials are excluded from generic diagnostics and serialization.

## Source scenario → target checks

| Frozen source | Target synthetic test |
| --- | --- |
| `tests/Enom/provider-contracts.php`: clean/malformed/duplicate envelope, contradictory errors, XXE, response cap | `enom-protocol-test.php`: parser input loop and catalogue limits |
| Same: secret-bearing transport exception, unsafe request overrides, no retry | `enom-protocol-test.php`: injection, invalid parameters, enum exclusion |
| `Api/RegistrarClient.php::send`: TLS, POST, fixed origins, response streaming, redirect/timeout refusal | `enom-curl-test.php`: namespaced cURL functions, no sockets |
| Neutral/account-binding additions | `enom-protocol-test.php`: wrong account, expired deadline, budget, summary/cast/serialize/clone |

Only synthetic fixtures were ported. Source captured account fixtures are excluded. Purchase/charge
commands, order/transfer reconciliation and write-specific refusal/status handling remain for
later admitted engine slices. No provider acceptance or installation is established offline.

Slice 10 returns protocol envelopes only; it exposes no neutral acknowledgement conversion.
Slice 11 must validate each named payload before building account-bound read evidence:

| Frozen source scenario deferred to slice 11 | Required target check |
| --- | --- |
| `RegistrarClient::validPayload` / provider-contracts Check cardinality + 210/211 | Exact queried domain, one row, recognized RRP |
| Same: GetDomainInfo/GetDomainExp expiry and ownership | Matching identity, owner UID + domain ID, strict expiry precision/aliases |
| Same: GetRegLock/GetRenew/GetWPPSInfo flags | Unknown absent/contradictory flags, no fabricated false |
| Same: GetContacts shape / contacts-regressions | Four roles, missing vs empty, no account-billing copy |

Order/transfer/expired-domain reconciliation reads (GetOrderList/GetOrderDetail, TP_GetOrder,
GetExpiredDomains) and DNS/host reads (GetDNS/GetHosts family, CheckNSStatus) are deferred to
their later engine slices, alongside their named validators. The test DOM module links the host
libxml2; fixture pinning covers the PHP module archive, not a separately pinned libxml2 build.

## Slice 11 named read normalization

`Driver` implements the neutral read interface. Its private conversion accepts only replies read
inside named paths; no caller can supply a raw reply or financial evidence. `Client` remains an
internal constructor: a production factory must be registered with `RegistrarRegistry`, reached
through `AccountBindings::reader` after provider identity acceptance and the live-site guard.
Tests use a synthetic verifier and a registry factory; no production verifier is installed here.
Suffixes are explicit, including compound endings; there are no default sellable endings.

| Frozen source | Target synthetic test (`enom-read-test.php`) |
| --- | --- |
| `Services/Catalogue.php`, `EnomApi::tldList/tldInfo` | Repeated/compound/malformed catalogue; bounded registration terms; paid privacy and unknown flags |
| `EnomApi::productPrice` / provider contracts | Exact lexical zero and action/term parameters; explicit false/0 is `no_price`; malformed/refusal/timeout is failed `unknown` |
| `Services/RegistryFields.php::normalize/supported` | Conditional child schema; malformed/empty dependencies close; explicit empty and frozen no-Attributes/ResponseCount=0 schema |
| `EnomApi::normalize(info)` / `DomainObservation` | Domain/SLD/TLD/owner UID/provider ID proof; another owner suppresses extra reads; per-read budget |
| `RegistrarResult::confirmedExpiry`, provider contracts | Date-only precision; Pacific winter/DST and UTC attributes; invalid/conflicting aliases unknown; GetDomainExp |
| `RegistrarClient::validPayload`, flag normalizers | Check one domain/recognized status; premium/platinum/EAP; nullable/conflicting renew/lock/privacy; GetExtendInfo no grace inference |
| `EnomApi::normalize(contacts)`, contacts-regressions | Four domain roles; account billing excluded; attributed/prefixed fields; empty vs missing; NotCollected; alias conflicts and redaction |

Protocol parameters now have per-command keys and bounded values. Non-UTF-8/NUL XML is refused
before parsing. `ReadResult` holds server-only catalogue/schema/contact facts outside diagnostic
serialization. It is not a customer DTO. Missing facts stay unknown independently.

Product prices deliberately remain lexical values with no invented currency or unit basis.
Quote/charge arithmetic and renewal assessment await pricing slice 07 and Enom slice 12.
Provider reconciliation remains unknown until the later order-evidence slice; acknowledgement
never establishes completion, no-charge proof, ordinary renewal grace or write admission.

Private contacts/expiry/renewal-info reads first obtain fresh GetDomainInfo ownership proof with
that same context budget; unknown or another owner blocks the follow-up request. Returned facts
include the proven name and provider reference. No ownership cache is introduced. The frozen
no-Attributes/ResponseCount=0 envelope is a supported empty schema; the count alone does not
prove emptiness when Attributes are present. Unsupported/malformed catalogue endings currently
fail the whole catalogue closed. Registry flag requirements are stricter than the source fallback.
Typed consumer DTOs must precede catalogue/contact consumers; this server-only intermediate bag
cannot authorize writes. Registration/purchase status and restorable facts remain for operation
and reconciliation slices; owned=true by itself never establishes registration completion.

## Slice 12 price and order evidence

Adapted from the same frozen revision's `Services/Catalogue.php::productCost`,
`RegistrarMoney.php`, `RenewalAdmission.php` and `Api/EnomApi.php` price/order reads.
`catalogueRate` retains selected-term annual registration rates and annual renewal/transfer,
using source USD semantics. `quote` exposes exact term totals, advisory for acquisition:
ordinary/premium/availability acceptance remains separate. A failed neutral quote never means
no-price. Only successful explicit `ProductEnabled=false/0` supplies null
catalogue rates; unsupported decimal precision/currency fails instead. Live prices retain the source four-decimal limit; extra precision fails the read
instead of withdrawing a prior price. Only database padding is trimmed when excess digits
are zero (the neutral pricing lane owns that mapping). No floats or rounding are used.
`Pricing::catalogue` checks binding/query/currency/future observation/expiry and divides totals
exactly. Persisted PriceSnapshot contracts remain unchanged. Quotes use a local age
(default 300 seconds, maximum one day), not a provider charge guarantee. Catalogue reads
require a separate caller-selected age (1 second to 31 days); slice 14 must choose it from
the accepted sync interval and failure margin. The source daily sync did not expire prices;
this explicit policy is required by the neutral catalogue contract.
RequestContext's additive `now()` accessor uses its accepted deadline clock for these checks.

Renewal assessment authenticates membership then reads GetExtendInfo/Check and, if expired,
GetExpiredDomains using precise instant comparison. Complete quotes require matching ordinary-name prices/currency, term limits,
known hold and corroborated precise expiry. Only the frozen scalar US local-date shape reconciles
against a scalar authenticated precise Pacific expiry across UTC dates; attributed authenticated
expiration shapes fail closed as in the frozen source. Date-only/unknown/restorable
facts never infer grace. Grace requires one matching Expired row and one year; restoration is
not authorized. Missing cost stays unknown independently of ordinary provider state. Failed required reads
return Unknown. Unobserved redemption vocabulary stays Unknown; Ineligible reflects the
source term/hold refusals without granting admission. Expired inventory is capped at 200 rows.

OrderCharge reads GetOrderDetail for a captured ID and requires a retained successful matching
Purchase/Register or Extend/Renew execution plus one exact successful closed item. Equal header
and item amounts prove USD using source OrderBillAmount semantics; different authorized charge
is retained as a discrepancy. Split/unknown/pending orders and UpdateExpiredDomains remain
unconfirmed. BillDate/expiry retain date precision and Pacific provenance. This is read evidence,
not completion, no-charge proof, unique attempt correlation, replay/refund or spending authority.
The internal execution input is only a consistency check; slice 22 must replace it with a
neutral persisted execution record and decide unique original-attempt charge acceptance.

| Frozen source scenario | Target test |
| --- | --- |
| Catalogue product_price_unavailable vs other read failure, selected-term annual costs | `enom-money-test.php`, `enom-pricing-test.php`: null vs throw, four-decimal live rates, rejected extra precision, eight-decimal DB padding, exact rates/totals, DB retention and PriceSnapshot |
| RegistrarMoney renewal currency, matching Check, max term/hold, precise local-date reconciliation; RenewalAdmission expired one-year rule | `enom-money-test.php`: ordinary/grace/unknown, foreign owner, budget, conflicting dates/prices/currency, scalar-only authenticated expiry, UTC/local midnight, hold/term/cap and restorable/redemption refusal |
| RegistrarMoney actualCost single item, execution/ID/domain/action/term/status/currency/total binding | `enom-money-test.php`: register/renew charge, malformed/split/unknown/null execution, expired-command refusal, exact discrepancy and date/redaction |

14 owns catalogue freshness/sync margin and a neutral catalogue interface; it must precheck
disabled servers, publish accepted capabilities and validate annual renewal;
20 owns admitted writes; 22 owns attempt/time-window correlation and transfer/expired-order
contracts; 23 owns saved expected-ID/expiry, auto-renew-off, balance, funding, grace enablement
and complimentary caps. Neither billing nor dispatch gains a cost floor. Provider acceptance,
installed hooks and production account identity remain open.

Slice 25 supersedes the earlier expired-command charge exclusion: explicitly admitted one-year
UpdateExpiredDomains accepts its nested ReactivateDomainName acknowledgement/order ID, then
requires the same exact singleton successful closed Renew item, original order/time binding and
owned full-term expiry as ordinary renewal. An acknowledgement never supplies actual cost or
expiry. Unknown mode/product/charge remains unresolved; real grace/order-vocabulary acceptance
is still required before installed processing. Enom acceptance without an ID needs accepted history.
