# Enom installed charge recovery

The installed Enom exchange scenario exercises native paid registrations, a
sealed Purchase with a lost reply, and recovery through the installed driver with a stubbed read transport.
It checks missing versus captured original OrderID, history acceptance closed
by default, incomplete empty-list replies, complete history with no matching
charge, ambiguous/duplicate history, restart without replay, exhausted
polling and audited staff read-only resume/recheck while processing is paused.

No cost, provider expiry or billing anchor is recorded until one matching charge
and owned full-term expiry agree. Funding loss before reads and drift during the
charge read both prevent completion. Restored funding permits read-only recovery.
Below-cost recovery completes with a staff alert. Invoices, invoice items,
transactions and credits remain unchanged. Captured-ID
recovery does not query account history, and completed attempts do not repeat reads.

History lists use sanitized Enom TEST GetOrderList structure. The captured
GetOrderDetail renewal supplies the detail metadata contract. Constructed multi-page
cases cover all 81 rows with contiguous 25-row pages, changing counts, repeated IDs,
missing pages, unknown metadata and insufficient budgets. These offline checks
do not establish retention, visibility lag or Live clock behavior.
Staff service routing/audit and reconstructed worker routing are exercised with
explicit test bindings; production staff composition and credentialed cURL reads
are outside this scenario. No production acceptance, action flag or processing
default changes here.


## Captured order local clocks

Registration reconciliation and renewal completion retain matching captured-order
bill dates as local wall-clock seconds with explicit unknown timezone provenance.
The sealed execution and exact original OrderID are required before checking
whether some UTC offset from -12:00 through +14:00 fits the original window.
Tests exercise a clock one hour late under the former Pacific interpretation,
both inclusive offset bounds, dates just outside each bound, a different OrderID,
and the same skew through strict accepted history. Retained renewal anchors
recheck that bound and identity; expiry and exact-charge checks remain required.
Date evidence rejects malformed local calendars and invented timezone provenance.
The strict-window mutation fails the real registration reconciliation regression.

Earlier fixtures used Pacific-consistent bill dates, masking provider-clock
uncertainty. These synthetic checks establish no provider timezone or acceptance.
History discovery retains strict bill-time matching, with TEST bills interpreted
as Mountain time and the unmeasured Live clock retaining Pacific;
provider evidence for timezone/DST behavior, visibility lag and completeness is
still needed before changing that contract. Expiry parsing is unchanged.

## Lost renewal replies and paged history

`renewal-enom-history-contract-test.php` starts with a funded, sealed renewal whose
reply was lost. Four list pages plus three details settle it in eleven reads
including holding checks: a recent registration, an older renewal and the unique
matching renewal are all validated. Missing orders, missing pages and ambiguous or duplicate
charges retain uncertainty, without another renewal send or native money changes.
`reconciliation-enom-history-test.php` also covers full-term and timestamp guards.

The captured list contains IDs, OrderDate and status only, with no domain or product
type. Whole receipt-clock days outside all send-time UTC offsets are excluded
before details. Every remaining row requires a detail; over 20 details or insufficient
remaining page/detail capacity keeps history incomplete. The request range spans
the same conservative whole days, rather than adding a day on both sides of an
assumed provider clock. No read-budget, send-limit or acceptance default changes.

A validated same-domain order with another action/term or a bill time outside the
sealed window is a known non-match, preserving complete history. The paid-renewal
regression includes a recent registration and an older renewal alongside the unique
matching renewal. Unknown dates, invalid charges, unsupported shapes and competing
renewals remain held. List rows must also say Order complete and processed before
a detail can supply a candidate; cancelled or failed list rows cannot settle it.
