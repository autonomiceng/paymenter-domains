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

Only synthetic fixtures were ported. Source captured account fixtures are excluded. Financial
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
