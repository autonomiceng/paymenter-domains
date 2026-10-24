# Testing coverage: staff review

Area coverage notes were split from the [testing coverage index](testing-coverage.md).
Setup and suites stay in [testing.md](testing.md).

## Staff request history

`staff-review-history-test.php` covers real Activity/detail ordering, escaped reasons and
names, cell truncation, the latest 3/20 limits, older-entry counts, malformed timestamps and
history, fresh permission refusal and real customer-page exclusion. Total Activity queries
stay at eight for both 1 and 4 rows. Removing the display, or bypassing escaping in either
the cell or the detail view, fails the corresponding render or escaping assertion.

`management-privacy-retirement-test.php` verifies an already-redacted reason stays redacted
after privacy-charge retirement, without reconstructing the actor name. These are offline
native SQLite/Livewire checks; installed browser and provider acceptance remain separate.


## Native staff inbox

`watch-maintenance-test.php`, `watch-notice-negative-test.php` and `registrar-balances-test.php`
assert one native notification per eligible staff user, exact existing copy, admin links and
unchanged episode/day deduplication. The unmodified core migrations must supply Paymenter's
required user/title/body columns; missing storage or columns skips silently. Queue assertions
reject Filament database notification jobs under a fake queue; empty jobs/failed_jobs checks
are baseline assertions, not worker execution. Native rows and the rejected job cover the
failed-job symptom. Earlier
maintenance tests checked only outage markers, notice tests only audit deduplication, and the
balance test expected zero rows from the native inbox. Native persistence assertions close
that gap; restoring a Filament send at any of these paths must fail. Offline queue fakes do
not establish installed push or worker delivery.


## Completed request actions

`staff-operations-test.php` and `staff-operation-reads-test.php` give confirmed completion
precedence over retained send seals and responses. Native Livewire checks hide the action
menu and direct service calls refuse every operation action without changing saved domain
or operation data. The domain page exposes domain-scoped recovery for current stopped scheduled work or record
holds; after resume it disappears again and the completed request has no menu. The static operations render fixture
also includes a completed sealed request. Removing success precedence, the locked terminal
guard, the stopped-work gate or menu visibility must fail these assertions. These are
offline native checks, not installed browser or provider acceptance.


## Expired seller cost refusals

`seller-policy-test.php` expires the selected account's registration or required annual
renewal cost and asserts typed account, ending, action, term and saved expiry context.
The prior seller and revision remain unchanged; a different fresh eligible registration
term still permits selection. `staff-tld-actions-test.php` submits the native Livewire
seller action and checks the exact UTC notification and Registrars refresh label,
unchanged policy and no provider reads. Enom TEST with import enabled gets the existing
Import accepted ending path; NameSilo gets the Cost refresh row on its own ending page; disabled TEST imports
get an explicit administrator recovery explanation rather than an unusable remedy. A selected-account
catalogue hold remains unchanged and the notification explains administrator recovery for
failed or interrupted reads alongside the healthy scheduled-read path. Replacing the typed notification with the generic
fallback or accepting expired evidence must fail these checks. Structurally invalid
evidence or a changed pricing rule keeps its existing refusal before expiry context. These native fixtures do
not establish an installed panel or deployed provider refresh workflow.

## Virgin renewal admission recovery

`staff-renewal-release-test.php` releases paid, virgin State/Cost/Owner/AutoRenew/Term
refusals through actor-bound staff review; Authority retains its existing release path.
Every other RenewalCode, retained attempt/seal/
possibly-sent identity, stale payment, refund marker and stopped lifecycle refuses unchanged.
Release clears the admission claim; normal queue dispatch and RunOperation repeat admission,
retain an unfixed refusal, then perform both full read sets and exactly one send after correction,
without another invoice/payment. The NameSilo execution suite covers unsent auto-renew release
and its Review instruction while retaining distinct sent-attempt reconciliation guidance.

## Renewal authorized cost

`staff-domains-test.php` covers historic admitted renewals with actual cost but no estimate,
the sealed USD authorization across completed and unresolved states, and unchanged retained
attributes after rendering. An existing estimate or renewal cap never replaces missing or
invalid authorization; zero and large exact amounts remain distinct from unknown. Registration
and transfer keep their checkout estimate labels, and renewal authorization requires cost rights.
These are synthetic native staff-fact checks, not installed panel or provider acceptance.

## Renewal billing review

`renewal-billing-settings-test.php` covers **Review renewal billing** on Registrars:
absent rows show Off/Off and the 21/7 calendar marked as defaults, and the modal prefills them.
A native submit writes exactly four plain rows and one audit entry with actor, reason, reviewed
input and effective before/after values. Replay, stale pages, unchanged values, missing keys,
non-boolean switches, non-integer days and calendar bounds (issue > due, issue ≤ 365, due ≥ 1)
refuse with exact copy and write nothing; 365/364 and 2/1 save. An invalid saved calendar is
reported with no effective days, never silently repaired. The action is hidden and the writer refuses
without the policy or action permission, and tickets bound to another actor or action refuse.
`renewal-billing-test.php` changes the calendar and switches through the reviewed writer after a
renewal invoice is issued and checks the invoice, items, transactions and operation stay unchanged.
These are synthetic native checks, not installed panel acceptance.

`staff-tld-writes-test.php` runs balance probes with explicit past and future timestamps while the container clock differs. `staff-domains-test.php` applies native status filters to pending provenance and the latest held or uncertain acquisition, including multiple selections.

`contact-setup-test.php` renders Registrars with all, one or no required user contact property definitions, rejects definitions for other models, and checks repeated views and restored definitions without changing saved properties.
