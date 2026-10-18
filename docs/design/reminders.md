# Dormant customer notices and staff alerts

Source: MIT Enom `444f31ce0bfd07216e5200544bffe863119d0e7d`,
`Services/Reminders.php`, `Services/TransferNotifications.php` and reminder scenarios.
Customer copy and data are registrar-neutral. Native notification templates remain
staff-editable and use Paymenter's template audit and customer channel preferences.
Installation creates missing keys only; it never resets a customized template.

The explicit worker has no installed registration, listener, scheduler or provider
transport. Enrollment defaults off. Schedule edits require fresh staff permissions,
a revision and a safe reason; encrypted retained history records the actor and
before/after values. Migration rollback retains schedules, delivery claims and alerts.

Every planner inspects each holding, even when another notice was already accepted;
selection skips claimed identities so a new credit cannot be hidden by a renewal window.
Each account has its own cursor and five-minute cadence. Durable oldest-turn order
prevents a failing account from starving another. Each turn handles at most 25
holdings; a sweep selects at most eight accounts by default (maximum 32). Missed
expiry offsets catch up only within two days, choosing the closest window. UTC
calendar dates and current locked native authority determine each notice. Issued
invoice notices share the pre-payment eligibility checks with renewal funding;
cancellation, pause, revision, anchor or sibling drift suppress the payment promise.

A delivery key binds domain, generation, expiry and reminder window, or the exact
transfer/refund operation. Template render failures are retryable before a durable
handoff claim. Claims commit before native queue/in-app delivery outside locks.
Acceptance means queued, never delivered. Uncertain handoff is never automatically
replayed; staff must inspect native queue/log evidence before any manual resend.
Disabled template or channel preferences suppress that window durably. Missing
templates and temporary channel unavailability alert without consuming a claim.
Render failures stay open until that exact notice is accepted or staff dispose of it.
Check failures require a future staff disposition.

Unknown expiry or price never becomes a customer guess or zero. Missing/held renewal
invoices, unknown cost, immutable per-operation below-cost evidence, unknown payment
time and notification failures create deduplicated retained staff alerts. Existing
domain details show safe labels; account setup shows saved reminder configuration.
Inspection runs even with customer delivery off; binding drift alerts and blocks
customer delivery. Successful inspections resolve obsolete evidence alerts without
deleting history. Unknown cost is staff-only; an exact issued invoice remains known
price evidence even if the current retail catalogue is unavailable. Complimentary
pricing gets a month-out support notice, never an unfunded automatic-renewal promise;
a missing execution request alerts at the saved due date. Below-cost
renewals remain billable and their known invoices remain eligible for reminders.

Paid transfer notices use the retained verified payment timestamp and current native
funding. They request a missing code before the thirty-day deadline, with one final
week reminder. Credit notices require a recorded native account credit, and never
invent a registry rejection or promise a cash refund. Credits older than two days
alert staff instead of backfilling customer notices. Overdue invoices show their past due date and request a support check, rather than
asking the customer to meet a past deadline or promising renewal after late payment.
Expired notices do not require a live invoice, including after unpaid retirement.
They make no
restoration or automatic-renewal promise.

Installed activation, enrollment of accounts for inspection, mandatory event notices
while customer schedules are off, binding recovery, audit-cap rotation, rotation-lag
monitoring, failure/uncertain disposition, template enrollment and route wiring,
operational monitoring and final acceptance remain the runtime integration gate.
Initial provider-observation publication remains the observation integration gate.
