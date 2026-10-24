# Enom TEST registration coverage

## Deployment settings

The registration key is deployment configuration only; it has no staff toggle. The following values illustrate the
Laravel config shape for one isolated TEST deployment:

```yaml
domains.enom_identity.environment: test
domains.enom_catalogue.enabled: true
domains.enom_writes.registration: true
domains.enom_test_daily_call_cap: 50
```

`domains.enom_writes.registration` must be boolean `true`, and registration stays closed unless the identity
environment is `test` and the TEST catalogue is enabled. Set the call cap as an integer (default `50`, `0` refuses
every Enom TEST request); if a source setting is a string, cast it to an integer when creating Laravel config. An
invalid value or unavailable counter refuses requests. One UTC-day counter is shared across every TEST account and
counts failed or timed-out requests as well as successful ones. This configuration never enables live registration.

The existing Registrar setup catalogue acceptance action records staff acceptance of the exact TEST Purchase route
and worker request interval alongside the TLD terms. A later protocol or interval change no longer matches that
record and holds registration. The USD requirement is checked against a recent, successful, fingerprint-bound manual
or preflight balance read. These checks do not establish Enom account/provider acceptance; no provider request is
made by these fixtures.

After correcting a never-claimed local hold such as missing USD evidence or route acceptance, staff use Release unsent
hold to admit a fresh worker attempt. A cap refusal after a worker claim can be released only when its retained
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
  the Registrar setup action says nothing was saved, and the unaccepted-route warning appears only when the
  deployment allows TEST registration.

The retained upgrade scenario remains `tests/test_installed_package_previous.py`. These offline
fixtures prove local composition and refusal behavior; they do not establish Enom account or
provider acceptance.
