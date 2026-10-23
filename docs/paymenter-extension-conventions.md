# Paymenter extension conventions

These are implementation constraints, not claims of implemented or tested behavior. The reference project is [Paymenter Enom](https://github.com/autonomiceng/paymenter-enom); its local development snapshot targets Paymenter 1.5.9.

## Native integration

Use a native server extension under `Domains/`. The planned class is `Paymenter\Extensions\Servers\Domains\Domains`, extending Paymenter's `App\Classes\Extension\Server`. Confirm the actual `ExtensionMeta` signature and extension discovery in the supported Paymenter version before implementation.

Expose configuration under Paymenter's native Servers interface and extension-owned administration resources. Keep Paymenter application, authentication, gateways, themes, and native table definitions unchanged. Each managed domain uses one native Service and the normal native invoice/payment lifecycle. A second subscription or direct registrar payment flow would duplicate billing authority.

## Keeping Paymenter upgradable

Paymenter must take new upstream releases without an extension release. Ship an extension release only when an upgrade actually breaks something.

- **Public surfaces only.** Use the extension base classes and their hooks, service providers, our own routes and tables, Eloquent model events and observers, Filament registration, Paymenter events, jobs, config, and the documented theme mechanism.
- **Never intercept Paymenter globally.** Do not rebind or subclass Paymenter services in the container, remap native routes, add global Livewire, middleware or request hooks (including `TrimStrings::skipWhen` or `TrimStrings::except`), replace database resolvers or query builders, monkeypatch, or edit core or vendor files.
- **Never gate on the Paymenter version.** No supported-version list at runtime, and no refusal, pause or banner just because the version changed. Disable behavior only on evidence that it is broken.
- **Guard invariants at the write.** A scoped observer refuses only the specific invalid change, logs it, and tells staff. Native renewals and saves continue for everything else.
- **Check required hooks, not versions.** If a feature depends on a Paymenter class, event or listener, verify it at boot. If one is missing, pause only that feature and show staff exactly what is missing.
- **Pin remaining internals in tests.** Where a guarantee still depends on Paymenter internals (template overrides, a core base class, native write paths), extend the sha256 upgrade tripwire. It runs only in tests and CI, names the changed files and the code that depends on them, and is never regenerated automatically. Update a hash only in the same reviewed change that re-checks or fixes the dependent code.
- **Qualify releases in CI.** New Paymenter releases run the full suites, the tripwire and the override-template diff. A green run needs no extension release.
- **Exceptions are architecture decisions.** A change that cannot follow these rules states why in its pull request and adds a tripwire before merge.

Known exceptions, being removed: the native Server and Service route remaps and Livewire guard (`Domains/Services/Runtime.php` `secureNativeRoutes()`, `Domains/Services/NativeServerGuard.php`) and the `TrimStrings` hooks (`Domains/Services/Runtime.php`, `Domains/routes/owned.php`). Do not add more.

## Ownership and spending

Domain ownership is an explicit registrar assignment. A hosting service's domain property alone does not authorize registration, observation, billing, or renewal. Design coexistence with other registrar extensions before offering shared navigation or adopting existing domains.

Represent paid, custom-priced, and explicitly complimentary domains separately. A zero retail price alone does not authorize registrar spending. Verify settled funding, current ownership, server/account binding, and prior-operation outcome before a write. Keep money in integer minor units or another exact representation; distinguish retail prices, quoted provider cost, and confirmed final charge.

An unknown result remains unknown until authoritative evidence resolves it. Provider timeouts and malformed replies are not definite failures. Retrying a renewal can buy another year; queue retries must not repeat uncertain writes.

Use native encrypted settings for credentials, explicit test/live account binding, and deliberate live activation. Default processing off. Copied databases must not accidentally activate live spending.

## Packaging and retained data

The planned ZIP has a single `Domains/` wrapper, not `extensions/Servers/Domains/`. Verify the supported Paymenter uploader and lifecycle hooks before claiming installation compatibility. Include the distributable MIT license. Exclude documentation placeholders from release archives once a package builder exists.

Use extension-owned tables and identifiers, with a reviewed schema. Preserve registrations, invoices, and operation evidence through upgrades and uninstall. NameSilo must never write Enom-owned tables or take over existing Enom domains implicitly.

## Attribution

New extension material is MIT-licensed to Autonomic Engineering. Source adapted from Enom must retain its original MIT notices, with source revision and contribution provenance recorded. The API foundation adapts Enom client/result/domain patterns; source files and revision are recorded in the implementation commits.
