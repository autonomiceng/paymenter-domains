# Receipt returns

Domains returns proved unsent failed orders through the native Paymenter gateway dispatcher. It does not import
another gateway extension. Paymenter 1.5.9 has no core refund method, so gateways may implement this small port:

```php
allocateReceiptReturn(Invoice $invoice, string $receipt, string $allocationKey, int $amountMinor): array;
receiptReturnStatus(Invoice $invoice, string $receipt, string $allocationKey): ?array;
```

AE Stripe implements the port in its receipt ledger. Other gateways implement the same methods on their native
Gateway class. Domains currently supports USD only. A gateway without both methods gets atomic account credit
as the bridge. A gateway that implements the port owns any fallback decision; pending or uncertain outcomes
never authorize Domains to add credit.

Domains commits the exact positive native receipt, gateway, invoice, amount and allocation key before calling
the gateway outside its transaction. Each operation/receipt return has one stable namespaced key. A retry
always reads status and reuses the exact reserved key and amount.
For closed-invoice receipts, the port must expose the gateway's automatic full return under `excess`. Domains adopts each exact
positive receipt and polls that automatic allocation; it never calls allocate for it or issues local credit.
A missing automatic result needs durable repair, not a second entitlement. Adoption also works when the
positive receipt and completed negative row arrive before Domains sees either. Incremental unsupported-gateway
payments continue through the atomic credit bridge. The automatic key is isolated in the adapter. Completed automatic receipt/reversal pairs are expected closure
activity; unrelated adjustments still raise review.
The gateway must serialize allocations against that receipt, including excess payments and other callers,
perform refund I/O idempotently, reconcile unknown results, and recover interrupted returns automatically.

Results contain integer `amount_minor` and `state`: `reserved`, `pending`, `review`, `refunded`, or `credited`.
Only the last two are complete. A later bank failure may move `refunded` to `review` or `credited`
under the same amount, refund ID and native adjustment key. Domains keeps polling refunded allocations,
never reallocates an existing return, and leaves fallback money and notices with the gateway. Completed results must include `key`, the unique native transaction identity
of the deterministic negative succeeded transaction the gateway commits with completion. A gateway credit
fallback and that reversal must commit together. `refund_id` and a safe `reason` may also be returned. AE
Stripe already returns this key. Domains retains it to distinguish completed returns from unrelated reversals
when a second failed line shares the invoice. The gateway also owns customer notices for its returns.

Local credit, receipt plans and append-only return markers commit under owner/invoice/operation locks. On a
failed proof or gateway uncertainty, `refund_repair` is retained in the domain obligation alerts. Review the
request and native invoice owner, issued line and signed adjustments; restore authoritative records through
their supported repair workflow, then the ordinary refund sweep retries. Never clear a plan or issue an
independent refund/credit to work around it. A positive amount returned by the worker is an observation, not
an instruction to pay it again.

The first actual registrar funds shortage starts a frozen three-day acquisition deadline. Read failures do
not start that timer. Readmission, claim and final seal all refuse elapsed waits. The bounded sweep closes
only requests whose complete current no-send proof and funding still hold. Changed or possibly sent work
gets `funds_wait_review`, without a return. Renewals immediately record `renewal_funds` and retain the ordinary
provider-safe boundary from admission (the exact Enom expiry, or NameSilo's existing ordinary margin).
They may retry until that boundary, regardless of elapsed days. At the boundary a paid, definitely-unsent
funds-short renewal closes, cancels its invoice to fence collection, and uses the same once-only return path.
Unknown attempts or missing retained deadline evidence stay in review. This does not authorize a replacement
cycle or a later grace renewal; P07/P22 must decide those separately. No grace deadline is invented here.

Expiry-retired renewals return incremental positive net settlements, less earlier local credits, completed
native returns and pending receipt allocations. A reversal beyond the remaining funds needs repair, never an
automatic customer debit. Overdue local credit notices retain their original delivery identity and recover
only when no handoff occurred; uncertain delivery stays under review.
