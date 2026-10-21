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
