# Domains money reads

## Closed invoice mail (P14)

`Services\NativePresenter::invoiceOutcome(User $owner, int $invoiceId): ?Presentation\InvoiceOutcome`
reads native ownership, exact invoice lines and retained operation closures. It works
inside the Paid event's `afterCommit`, before the return sweep. It makes no provider
or gateway calls, takes no locks and writes nothing. Null means no matching closed
Domains line belongs to this owner; it says nothing about other invoice items.

The result has `kind`, `money`, nullable formatted `amount`, `domains` (names), and
`lines` (each `kind` and `domain`). Kinds are `renewal_cancelled_unpaid`,
`renewal_funds_expired`, `acquisition_cancelled_unpaid`, or `mixed` for multiple
closure kinds. Only the named domains are closed; do not describe other invoice
lines as cancelled. A later domain authority generation does not erase the
closed operation on an older invoice. This display grants no refund authority.

An unsent acquisition also counts as cancelled while its native cancellation
record/intent or cancelled service prevents purchase, before retirement can finish.
It reports `acquisition_cancelled_unpaid`, `pending` and a null amount. A retained
closure snapshot alone does not close a purchase that can still run.

Money is `pending`, `returned` (original payment), `credit`, or `mixed` (both
original payment and credit). `pending` includes unobserved or review outcomes and
new receipts not yet covered by a recorded return. Never promise credit merely
because an invoice closed. The capable gateway owns its automatic closed-invoice
return; Domains' bridge credit is for receipts it cannot return.

`amount` is the total recorded return entitlement, not a guessed receipt amount or
recoverable debt. It is normally null at the initial Paid event, until the return
sweep records an entitlement. A pending result may have a recorded amount whose
completion is still being checked. This read does not advance that check.

## Reversed receipts and outside-gateway credit (P05)

`Services\ReceiptReversals::forInvoice(Invoice $invoice): array` combines the configured
gateways' optional local `receiptReversals` observations with Domains' retained
`refund` and `late_credits` records. It does not fetch Stripe, allocate a return,
credit/debit an account, or change a service. No observation is not proof of no
reversal. Native invoice ownership must still match the Domains ledger and service.

Each observation keeps the gateway fields and adds:

- `domains_credited_minor`: exact credit from Domains outside the gateway ledger,
  attributed to this native receipt, including unsupported-gateway bridge credit.
- `domains_unattributed_minor`: invoice-wide Domains credit whose older payment
  snapshot cannot prove a receipt split. This repeats as context on observations;
  do not sum it across observations or assign it to a disputed receipt.
- `credit_balance_minor`: fresh current pooled balance in the invoice currency,
  including zero after spending. Zero does not erase recorded credit.

Observations are deduplicated by configured gateway, receipt, kind and ID.
Gateway `credited_minor` stays separate; Domains never adds the same fallback
credit again. Amounts are integer currency minor units. Pooled credit cannot prove
which receipt was spent, a debt amount, or permission to debit/refund.

Staff with View domain costs see current reversal context under Credit on the
existing Registrar request page. Open disputes ask for Stripe evidence before the
deadline; other review outcomes point to invoice and credit history. This manual
reconciliation is needed because pooled balance and older snapshots cannot prove a
safe automatic correction. Resolved observations show their current status rather
than keeping an old inbox alert open. Missing gateway reads ask staff to check the
invoice payment reversal and credit history. No new hold, button or workflow exists.
