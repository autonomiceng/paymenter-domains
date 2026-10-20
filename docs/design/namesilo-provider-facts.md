# NameSilo provider facts

Confirmed with provider support, 2026-10.

These facts supersede the corresponding unanswered questions in the earlier
[API contract](api-contract.md) and [parity matrix](parity.md). Confirmation does not
establish installed integration or successful OTE contract tests.

| Fact | Design consequence | Where enforced / remaining acceptance |
| --- | --- | --- |
| Sandbox is API-only OTE at `https://ote.namesilo.com`, with the same API reference and `/api/{command}` paths. Registry OTE availability differs from live availability; registered names become unavailable to other sandbox users. Use .com/.net for consistent results. OTE nameserver changes accept only NS1–NS8.NAMESILO.COM; this does not limit live, where customer nameservers remain as in the [API contract](api-contract.md) and [parity matrix](parity.md). There is no sandbox request limit, but access is blocked during .com/.net drop-catch windows. Transactions without a payment id use test account funds. OTE `/apibatch` support is unconfirmed. | Isolate test credentials and funds; choose synthetic .com/.net cases and allowed nameservers outside blocked windows. A sandbox purchase changes shared OTE state. Retain the Sandbox `/apibatch` route until contract testing resolves it. | Fixed origin: [Environment](../../Domains/Registrars/NameSilo/Api/Environment.php). Routing: [RequestContext](../../Domains/Registrars/NameSilo/Api/RequestContext.php). OTE contract writes send only NS1/NS2.NAMESILO.COM ([GuardedTransport](../../tests/contract/GuardedTransport.php)). OTE scheduling and endpoint acceptance remain operational test gates; synthetic fixtures do not establish them. |
| Customer-initiated `/api` work permits one active request per second. Scheduled/background `/apibatch` work permits 2–4 active requests. No failed-request suspension threshold is published. | Keep origin explicit, space interactive starts by at least one second and use the conservative batch concurrency of two. Never automatically retry failed requests. | [AccountReadBudget](../../Domains/Services/AccountReadBudget.php), [transport](../../Domains/Registrars/NameSilo/Api/CurlTransport.php) and [installed limiter gates](observation.md). The local 60-wire/minute ceiling remains even in OTE; the absence of a sandbox limit does not remove it. |
| After a registration, renewal or transfer timeout, inspect domain status and `listOrders`/`orderDetails` before considering any retry. There is no idempotency key or maximum-price parameter. | Preserve the original attempt and uncertain outcome; reconcile read-only. Status or expiry alone cannot prove a charge. A fresh quote cannot cap the final provider charge. | [Correlation](../../Domains/Registrars/NameSilo/Correlation.php), [operation reconciliation](../../Domains/Services/OperationReconciliation.php), [renewal completion](../../Domains/Services/RenewalCompletion.php) and [transfer completion gates](transfers.md). No automatic purchase replay. |
| Failed-transfer recovery with `transferUpdateChangeEPPCode` or `transferUpdateResubmitToRegistry` depends on the current status and failure reason. Registrar transfer refunds require manual provider support action. | Recovery needs an explicit evidence-based decision; never resume or request a provider refund automatically. Local definitely-unsent account credit is a separate financial disposition. | [Transfer recovery and sent-rejection rules](transfers.md). Recovery commands are not implemented; sent failures retain financial uncertainty and cannot use the local unsent-credit path. |
| Always send `auto_renew=0`. Account auto-replenishment is a separate setting that operators should disable. | Explicit purchase flags plus owned readback protect local renewal authority; disabling domain auto-renew does not disable account replenishment. | [PurchaseMapping](../../Domains/Registrars/NameSilo/Api/PurchaseMapping.php) and [registration readback](../../Domains/Registrars/NameSilo/Registration.php). The admitted [RenewalWriter](../../Domains/Services/RenewalWriter.php) currently sends only domain/years; explicit renewal-flag wiring and the [renewal acceptance gate](api-contract.md) remain release work. Account replenishment is an operator setting, not an API control here. |
| Registered nameservers (glue) support IPv4 only. | Refuse IPv6 glue; DNS AAAA support cannot enable it. IPv4 glue still requires its own accepted implementation. | [Management](../../Domains/Registrars/NameSilo/Management.php) refuses all glue before I/O. Existing [management regression](../../tests/management-namesilo-test.php) explicitly rejects IPv6 add/update without a write. Preserve that refusal if IPv4 glue is later enabled. |

## Open gates

- OTE `/apibatch`: confirm the endpoint contract before accepting background OTE work.
- Failed-request suspension threshold: unknown; retain no automatic retry of failed requests.
- `checkTransferStatus` `expiration`: confirm field semantics and agreement with owned expiry.
- Transfer price changed: confirm the explicit accept/cancel workflow and financial outcome.
- Premium cases: establish availability, exact-term costs and lifecycle behavior independently;
  unknown premium evidence remains ineligible.

Other existing account, renewal, history, registry and installed-runtime acceptance gates remain
in force. No provider request or OTE acceptance is implied by these documentation changes.
