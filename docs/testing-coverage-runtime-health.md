# Runtime health coverage

`runtime-health-test.php` acquires and releases real minute-budget leases for an
interactive request and a background request and checks that both count against one
shared minute budget.
This budget includes balance and catalogue reads as well as registrar changes;
identity verification and
our deployment-wide daily call cap for Enom TEST remain separate.
These are synthetic offline SQLite checks; no registrar traffic or shared-database
concurrency acceptance is implied.

`binding-test.php` refuses processing activation without an acceptance callback before runtime registration and preserves the disabled state after a rejected callback. `management-test.php` polls live leases, unsealed attempts, exhausted read budgets and terminal states twice without I/O or mutation; binding failures still throw.
The cadence text counts selected operations, domains and TLDs separately from the shared registrar request limiter. The native health test checks both units.
Processing review refuses unchanged state and unsupported cache/session/queue settings or short retry windows.
Stale settings tickets reach the stamp guard for processing and notification reviews.

`test_hook_attribution.py` starts fresh console and HTTP kernels against a disposable installed
extension and retained SQLite database. HTTP binds its request before bootstrap. Native provider
discovery precedes assertions; healthy boots emit no hook warning. Removing only saving after
provider boot checks exact warning and deferral context, argument/query/path redaction, release
fallback, unchanged paid operation/financial bytes, zero sends and one diagnostic per operation
across scheduler ticks and a second RuntimePorts instance. Fresh healthy processes recover the
same registration and transfer through genuine action bridges and exercise the real expiry guard.
Both native-paid matrices check the shared process notice in NativePaidHooks, Registrars,
DomainFacts and ViewDomain, then clear it by restoring the original saving callbacks. Lost invoice
Paid wake recovery remains independent. These are offline SQLite/kernel/Livewire checks, not
installed worker, browser, registrar or shared-database acceptance.
