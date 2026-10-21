# Testing coverage: reminders and notices

Area coverage notes split out of [testing-coverage.md](testing-coverage.md), which is at its
size cap. Setup and suites stay in [testing.md](testing.md).

## Reminder review regressions

Staff schedule facts distinguish `[0]` (`0`) from `[]` (`None`) for both expiry offsets.
Both holders' actual credit notices retain a staff alert and no delivery claim while
mandatory mail is paused, then queue exactly once when mail resumes; optional mail still
permits in-app delivery. Watch templates can also be customized to force mail: a pause
leaves no mail identity, preserves a prior reservation and records existing notice health
evidence, while app delivery remains independent. Restoring the faulty schedule fallback
and forced-mail guards must fail these assertions. These are synthetic offline checks,
not SMTP delivery evidence.
