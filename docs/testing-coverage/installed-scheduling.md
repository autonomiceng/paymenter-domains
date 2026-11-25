# Installed lifecycle admission

Installed package lifecycles start at the head of the native category. Previous failures
still come first, shortest first; within the installed and ordinary groups, longer recorded
durations start first.
At most two installed lifecycles run concurrently (one with two workers). Pending installed
cases yield unused worker slots to ordinary native work. This spreads installed work across
the native schedule instead of starting every installed scenario together at its tail.

The scheduler preserves standalone installed exclusivity, the drain before a UI check, selected commands,
plan indexes, process cancellation and receipt finalization. It changes no phase/test cap,
assertion, fixture or host quick-lane admission policy.

`test_runner_installed_schedule.py` runs actual children against FIFO barriers: the first
two installed children must start before ordinary work, ordinary work must run while they
are blocked, and later installed children must remain unstarted until a slot is free.
A second case drives the scheduler with a UI check that outlasts its neighbours: with
`DOMAINS_TEST_CGROUP` set, ordinary native work starts beside it, never an installed
lifecycle and never more than the 2 GiB scope fits: four at wide ceilings, two at local
ones; without it, nothing starts beside the UI check. A third case gives an installed
lifecycle a previous failure: nothing starts beside the UI check, and it runs next.
The existing runner tests retain fail-fast, interruption, output and integrity evidence.
