# Testing coverage: staff review

Area coverage notes split out of [testing-coverage.md](testing-coverage.md), which is at its
size cap. Setup and suites stay in [testing.md](testing.md).

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
seller action and checks the exact UTC notification and Registrar setup refresh label,
unchanged policy and no provider reads. Enom TEST with import enabled gets the existing
Import accepted ending path; NameSilo gets the Cost refresh row on its own ending page; disabled TEST imports
get an explicit administrator recovery explanation rather than an unusable remedy. A selected-account
catalogue hold remains unchanged and the notification explains administrator recovery for
failed or interrupted reads alongside the healthy scheduled-read path. Replacing the typed notification with the generic
fallback or accepting expired evidence must fail these checks. Structurally invalid
evidence or a changed pricing rule keeps its existing refusal before expiry context. These native fixtures do
not establish an installed panel or deployed provider refresh workflow.
