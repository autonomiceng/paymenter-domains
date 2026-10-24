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

All responses are synthetic, reusing existing history and holding fixture shapes;
these checks do not establish Enom TEST acceptance. Actual GetOrderDetail and
GetOrderList replies, pagination/completeness, timestamps, retention and visibility
lag still require provider evidence before the history contract can be accepted.
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
History discovery retains its accepted Pacific timezone and strict window;
provider evidence for timezone/DST behavior, visibility lag and completeness is
still needed before changing that contract. Expiry parsing is unchanged.
