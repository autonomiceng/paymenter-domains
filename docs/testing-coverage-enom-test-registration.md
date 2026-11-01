# Enom TEST registration coverage

## Deployment settings

The registration key is deployment configuration only; it has no staff toggle. The following values illustrate the
Laravel config shape for one isolated TEST deployment:

```yaml
domains.enom_identity.environment: test
domains.enom_catalogue.enabled: true
domains.enom_writes.registration: true
domains.enom_test_sends_per_day: 1000
domains.enom_test_sends_per_minute: 60
```

`domains.enom_writes.registration` must be boolean `true`, and registration stays closed unless the identity
environment is `test` and the TEST catalogue is enabled. Set the send limits as integers (defaults `1000` in any
24 hours and `60` per minute; `0` refuses every Enom TEST request); if a source setting is a string, cast it to an
integer when creating Laravel config. An invalid value or unreadable count refuses requests. Each TEST login has its
own count, which includes failed or timed-out requests as well as successful ones. This configuration never enables live registration.

Saving What you sell records the fixed TEST route and worker request interval alongside the TLD terms; neither is a
staff tick box. A later protocol or interval change no longer matches that
record and holds registration. Preflight reads the fingerprint-bound USD balance immediately before sending;
a cached balance never blocks entry to preflight. These checks do not establish Enom account/provider acceptance; no provider request is
made by these fixtures.

Old cached-balance holds remain held until staff press Release hold. Route acceptance holds also need explicit release.
A real preflight shortfall says the registrar balance is too low. After adding funds, staff press Release hold once;
the worker rechecks the current funding, authority and balance using the original unsent claim. A send limit refusal after a worker claim can be released only when its retained
definitely-unsent refusal evidence validates; uncertain or possibly sent Purchases stay closed to release.

`tests/test_installed_package_enom_test.py` exercises the opt-in and call budget from a fresh
installed ZIP. Its socket-free cURL seam counts physical Enom requests and records the selected
endpoint. The fixture covers:

- The preview-only checkout refusal while the deployment registration key is absent, including no order, invoice,
  payment or Enom request.
- Strict boolean opt-in, TEST identity and enabled TEST catalogue requirements; live, unset,
  other-registrar and non-boolean configurations cannot compose registration.
- Pure registration-only runtime construction, unchanged processing default, separate staff
  processing approval, transfer/renewal/management refusal, and zero-call page projections.
- Counted identity, balance, catalogue, search, preflight, Purchase and captured-OrderID reads; the existing staff
  Registrar balances “Refresh now” action makes one counted balance request and preserves prior USD evidence when
  the local cap refuses it. A mid-import cap remains actionable, identity day rollover remains a typed local refusal,
  and prior history acceptance cannot open TEST history reconciliation; the Refresh notice shows cap usage/reset facts.
  Failed requests spend their reservation, and a zero cap refuses before the socket seam.
- A single worker Purchase, restart reconciliation, duplicate prevention, definitely-unsent
  Purchase cap hold, and partial reconciliation cap that retains Processing without replay.
- Native runtime turns continue to enqueue TEST operations while skipping registrar balance
  polling and general observation. Their durable domain cursor still runs local retirement,
  identity repair and billing work, including retirement after a missed cancellation callback.
  Queued capacity retries, staff-released unsent holds, and
  pending captured OrderID reconciliation reach the normal worker path; the scheduler itself makes
  no Enom calls. A local counter refusal also preserves the most recent successful USD balance row.
- Deployment-wide conditional counter reservations under concurrent PHP processes, strict
  Purchase scope/fingerprint/single-use checks, and retained counter schema/data after runtime
  removal and reinstall.
- Exact staff wording for TEST hold labels, cap facts and the Refresh now refusal. An unticked route review through
  the action on Registrars says nothing was saved, and the unaccepted-route warning appears only when the
  deployment allows TEST registration.

The retained upgrade scenario remains `tests/test_installed_package_previous.py`. These offline
fixtures prove local composition and refusal behavior; they do not establish Enom account or
provider acceptance.


## Local cap recovery for reads

`observation-test.php` injects the typed TEST cap refusal and checks that scheduled work
retains its try count and prior safe code, clears the lease and retries at the next due tick
without staff intervention. `observation-records-test.php` covers primary reads with records
enabled, record-family discovery and child-host reads: inventory is retained and no record
hold is created. Generic transport/contract failures still stop under the existing tests.
All typed local cap refusals defer, including zero/invalid limits, an unavailable
counter and an elapsed TEST request deadline; they retry every 60 seconds without consuming tries until the deployment
restores capacity. The cap-zero regression checks repeated due turns and recovery.
No new domain-level status is recorded; the Registrars page retains the daily-call facts.
In the current Enom composition, scheduled observation skips TEST write-enabled
accounts and the preview reader refuses domain reads before transport, so this
observation cap handling is defensive; reconciliation job recovery is separate.
Removing any cap-deferral catch must fail the corresponding regression.

`test_installed_package_enom_test.py` consumes the scheduler's captured `RunOperation` through
its queue-harness job handler after raising the cap. It asserts the seven confirmation reads,
completion and no second Purchase. This is an offline installed-package queue-harness check, not a
deployed scheduler/worker delivery claim.
The three-year short-readback case ties the observed order reference to the Purchase response.
