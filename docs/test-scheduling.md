# Test scheduling

Use `scripts/test.py --fail-fast --jobs 2` for a full run that cancels in-flight
process trees on the first failure. Without `--fail-fast`, the runner stops
scheduling and lets in-flight checks finish. Short lint, provider, contract,
runner and package checks precede native checks. `--timings-from receipt.json`
puts previous failures first within each category, then installed scenarios, then
faster files. It never changes the selected case set. At most two installed
scenarios run together (one with two workers); ordinary native checks can use
remaining workers while further installed scenarios wait.

Wide runs share the verified PHP executable and libraries only after sealing their mount
read-only in the private namespace. Mutable application files, databases and temporary
directories remain private. Ordinary runs keep separate PHP copies. Content guards still
verify all runtime bytes after execution. Shared commerce fixtures submit deferred credit
selection with checkout in one Livewire request, preserving the real mount and update cycle.

Under per-test cgroup admission, ordinary installed phases have 30 seconds, plus at most
two seconds for cleanup and resource evidence. Existing scenario deadlines still include
setup and cap every phase. The existing Enom live phase retains its 60-second admitted limit. Renewal now
uses focused ordinary phases under the 30-second limit.

`--memory-mib` defaults to 2048 and reserves 1024 MiB per worker. Parallel
concurrency is bounded by `min(--memory-mib, available - 1024) // 1024`.
Available memory is `MemAvailable`, lowered to the smallest cgroup v2 headroom
(`memory.max - memory.current + inactive_file`) of the runner's group, from
`/proc/self/cgroup` mapped through the cgroup2 mount root in `/proc/self/mountinfo`,
and its visible ancestors. A group with a `max` limit, or with an
unreadable or malformed `memory.max`, `memory.current` or `inactive_file`, sets
no bound. A missing `memory.stat` gives no cache credit.
The runner counts cache protected by `memory.min` in a sibling group as reclaimable.
On such hosts, set `DOMAINS_TEST_JOBS` to a worker count that fits the memory available
without reclaiming that protected cache. Explicit counts are still checked against the
runner's calculated budget.
The 1024 MiB host reserve applies only to available memory; the default budget
permits two workers. Explicit `--jobs 1` retains serial support on small hosts
and requires only a budget of at least 1024 MiB. This is a scheduling reservation,
not a kernel memory limit. UI checks always drain the pool and run alone.
Installed-package checks also drain the pool and run alone when `DOMAINS_TEST_CGROUP`
is unset. With it set, at most two installed lifecycles run concurrently (one with
two workers), leaving spare workers for ordinary native work. Standalone installed-package
and UI checks require a budget of at least 2048 MiB. Worker fixtures remain private.
CPU-based default jobs shrink to fit memory, down to one worker on small hosts.
Receipts record the requested and actual counts and the reason for that reduction.
An explicit `--jobs` or `DOMAINS_TEST_JOBS` count that cannot fit fails with the
number of workers that fit; use that count or serial mode.

Linux subreapers retain ownership when a test parent exits before a detached
child. Normal completion also drains owned descendants. Resource-owner timeouts
and interruptions allow their existing bounded graceful cleanup before escalation.
Peer cancellation gives cleanup handlers one second, then the supervisor kills
and reaps remaining descendants. An external five-second watchdog escalates only
if the supervisor fails to return. A readable kernel child inventory is required.

Short `php -l` syntax checks run directly, without a supervisor or RSS measurement;
their peak RSS is unknown (`null`). Other commands, including PHP compatibility
tests in the lint category, retain supervision and GNU time peak child RSS in KiB.
Receipts include the complete
command plan, first failure index, cancelled results and `not_run` commands.
Cancelled commands can have unknown RSS; they never count as passes. `failures`
lists commands that failed on their own or changed the runtime, `cancelled` lists
commands stopped by fail-fast or interruption, and `not_run` lists commands never
started. A cancelled command alone still leaves the run incomplete. `git_tree` is
the HEAD tree when the checkout was clean and unchanged from start to finish;
otherwise it is null and `git_tree_error` says why. Setup receipts record it too. Output files
use plan indexes even when commands finish out of order. Category summaries report
summed child time and maximum known child RSS, not aggregate simultaneous memory.
Category RSS is null when no measurement is known.
Interrupted commands retain captured output; output-write failures are recorded.
