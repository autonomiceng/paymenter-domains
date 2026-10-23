# Registrar balances

Staff with `admin.domains.view` can open **Domains → Registrar balances**. Each card
identifies the registrar, native server account number and environment. It shows the last
known balance and currency, its UTC read time, the renewal forecast and a safe read-error
category. Credentials, account fingerprints and provider response text are never shown.
**Add funds** opens the official registrar site in a new tab. Enom uses its account home
page because its public refill instructions do not publish a stable refill link.

## Reads and storage

The existing scheduler attempts one balance read per account every 24 hours. **Refresh now**
allows one attempt per account every 60 seconds. Both use the existing provider-login wire
limiter and never wait. Configuration failures consume that attempt's cooldown. A local
quota refusal preserves the last provider result; a daily attempt becomes eligible again
after 60 seconds. Spending can remain paused while otherwise authorized reads run.
The existing registration, transfer, renewal-admission and Enom management balance reads
and staff connection checks also record their result. They make no additional provider requests and keep their existing
spending checks. Only the existing balance commands are used.

`domains_registrar_balances` is extension-owned and retained on rollback. Its unique key
is `(server_id, account_fingerprint)`. Amounts are exact decimal strings with up to six
fractional digits; unknown amounts are null. Successful reads save amount, currency,
`read_at`, source and attempt time. Failed reads preserve the last known amount and read
time, set a safe error category and show **Unknown**. Sources are `daily`, `preflight`,
`renewal` and `manual`. The row also stores independent daily/manual cooldowns and the UTC
date of its last shortfall notification. Installation verifies the table even when its
migration has already run. Older read completions cannot overwrite newer attempts.

## Renewal forecast

For each current holding, use the **earlier** of the Paymenter renewal due date and the
confirmed registrar expiry. Before invoicing, the due date comes from the saved billing
calendar; an issued renewal keeps its invoice due date. If only one date is known, use it.
If neither date is known, count the renewal as unknown, never as zero. Dates are UTC calendar
days through day 30 inclusive. An overdue unsent renewal counts as due now, even without
an open invoice and while registrar expiry is still ahead. Native service expiry is not a registrar expiry.

Count only current, managed holdings with an active native service. A future-only renewal
stop or native end-of-period cancellation excludes unissued renewals, but already-issued,
unsent obligations that retain spending authority still count exactly once. Their next
cycle is excluded while the holding remains stopped. Skip immediate cancellations,
retained cancellation intents, retired or cancelled holdings, paused renewal spending,
and renewals already sent or completed. A definitely unsent attempt remains eligible,
including a sealed attempt with the full validated local prewire-refusal proof.
Open, issued renewals that have not been sent count even when their dates fall outside
the 30-day window, including complimentary obligations. A cancelled invoice, retired cycle
or refunded cycle is excluded, even if the operation still says it is awaiting payment. Account, registrar and authority
generation must match the holding. The current default seller has no effect.

Use a fresh USD wholesale price for the exact renewal term on the holding's account.
Missing, stale or unsupported costs count as unknown. Do not multiply an annual price to
invent a missing multi-year quote. The displayed total includes known expected costs;
**Unknown renewals** reports the omitted renewals. Any unknown date or cost is also named
in the status. Exact decimal addition and comparison do not use floating point.
A balance equal to the known total is **OK**; a lower balance is **Low for upcoming renewals**.
The forecast is advisory and grants no permission to invoice or spend.

After a successful daily read finds a shortfall, staff with the Domains permission receive
one Filament database notification per account per UTC day, when the native database inbox
has a compatible user relationship and table, and is enabled. Otherwise the card shows the status. No email is sent. The pinned
native test application has a different notification format and does not enable the
Filament database inbox. It uses dashboard status only.
