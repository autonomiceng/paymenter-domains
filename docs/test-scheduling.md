# Test scheduling

Use `scripts/test.py --fail-fast --jobs 2` for a full run that cancels in-flight
command groups on the first failure. Without `--fail-fast`, the runner stops
scheduling and lets in-flight checks finish. Short lint, provider, contract,
runner and package checks precede native checks. `--timings-from receipt.json`
puts previous failures and faster files first within each category; it never
changes the selected case set.

`--memory-mib` defaults to 2048 and reserves 1024 MiB per worker, with another
1024 MiB left for the host. Effective concurrency is the smaller of that budget,
available memory and `--jobs`. This is a scheduling reservation, not a kernel
memory limit. Installed-package checks still drain the pool and run alone;
they require a budget of at least 2048 MiB. Worker fixtures remain private.

GNU time supplies per-command peak child RSS in KiB. Receipts include the complete
command plan, first failure index, cancelled results and `not_run` commands.
Cancelled commands can have unknown RSS; they never count as passes. Output files
use plan indexes even when commands finish out of order. Category summaries report
summed child time and maximum child RSS, not aggregate simultaneous memory.

