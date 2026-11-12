# Installed lifecycle admission

Installed package lifecycles start at the head of the native category. Previous failures
still come first; within the installed and ordinary groups, recorded duration orders cases.
At most two installed lifecycles run concurrently (one with two workers). Pending installed
cases yield unused worker slots to ordinary native work. This spreads installed work across
the native schedule instead of starting every installed scenario together at its tail.

The scheduler preserves standalone installed exclusivity, the UI barrier, selected commands,
plan indexes, process cancellation and receipt finalization. It changes no phase/test cap,
assertion, fixture or host quick-lane admission policy.

`test_runner_installed_schedule.py` runs actual children against FIFO barriers: the first
two installed children must start before ordinary work, ordinary work must run while they
are blocked, and later installed children must remain unstarted until a slot is free.
The existing runner tests retain fail-fast, interruption, output and integrity evidence.
