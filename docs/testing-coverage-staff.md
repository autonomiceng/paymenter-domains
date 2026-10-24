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
