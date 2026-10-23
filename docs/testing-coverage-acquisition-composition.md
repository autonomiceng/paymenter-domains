# Acquisition composition coverage

`RuntimeComposition::make` is an explicit deployment factory. It returns the seven
roles required by `RuntimePorts::TYPES`, with `AcquisitionPorts` in the acquisition
slot. The selector holds separate registration and transfer ports; each named port
factory builds its own real preflight, bridge and engine. The selector enforces identical
`WriteFeatures`, `WriteAcceptance` and optional profile objects across both ports. NameSilo additionally requires the
same `RegistrationProfiles` instance in acceptance, both preflights and protocols.
Renewal uses its separate acceptance, execution, writer and completion path.

The factory requires explicit account-bound registration, transfer and management
protocols plus an injected renewal exchange. It performs no provider reads, sends,
processing changes or container registration. A deployment registers its pure
factory under the verified account fingerprint using `RuntimePorts::register`.
No default sender, provider acceptance, catalogue acceptance or processing authority
is supplied. Renewal and management flags remain independent of acquisition flags.

`tests/test_installed_package_acquisition.py` builds and verifies a fresh ZIP, uses
native upload/install, and starts a separate process with extracted runtime classes.
It has no source-tree runtime autoloader. Network functions are disabled by the
pinned test harness; all provider paths use explicit synthetic readers or protocol
spies. Existing registration and transfer response fixtures are reused.

The installed scenario covers:

- Pure construction and exactly seven complete roles; legacy single-engine rejection.
- Fixed registration/transfer protocols, selector position, unsupported registrar,
  mismatched protocol/account, separate policy objects and mismatched NameSilo profiles.
- A mismatched acquisition selector rejected independently of a matching management
  protocol; unavailable, disabled and enabled local action facts remain distinct.
- Wrong operation action, server, registrar and fingerprint refused before claims,
  preflight reads or protocol exchanges.
- The same native account alternating paid register/transfer/register/transfer through
  queued `RunOperation` jobs and `RuntimeWork`, with one correct monetary exchange
  per operation, action-specific fresh preflights and no replay on duplicate execution.
- Premium preflight refusal for both actions, unsealed with no exchange.
- Renewal refused by its independent disabled feature, never by an acquisition sender.
- Staff facts with registration off/transfer on and the reverse feature map; local
  composition does not imply provider acceptance or processing permission.

The existing runtime worker and physical-registration capacity fixtures now use the
same pure factory. The native payment matrix, late-contact replay and runtime records
fixtures also supply genuine typed ports and explicit offline exchanges. They retain their reconciliation, activation, renewal, shared
write-slot and native paid-worker checks. Retained database upgrade coverage remains
in `tests/test_installed_package_previous.py`.

These are offline composition and engine checks. They do not establish provider
identity, live transport, financial completion, actual-stack UI or database
concurrency acceptance. A reviewed bounded authenticated exchange is still required
before any deployment can supply real registration/transfer/renewal/management sends.
