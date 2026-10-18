# Paymenter extension conventions

These are implementation constraints, not claims of implemented or tested behavior. The reference project is [Paymenter Enom](https://github.com/autonomiceng/paymenter-enom); its local development snapshot targets Paymenter 1.5.9.

## Native integration

Use a native server extension under `NameSilo/`. The planned class is `Paymenter\Extensions\Servers\NameSilo\NameSilo`, extending Paymenter's `App\Classes\Extension\Server`. Confirm the actual `ExtensionMeta` signature and extension discovery in the supported Paymenter version before implementation.

Expose configuration under Paymenter's native Servers interface and extension-owned administration resources. Keep Paymenter application, authentication, gateways, themes, and native table definitions unchanged. Each managed domain uses one native Service and the normal native invoice/payment lifecycle. A second subscription or direct registrar payment flow would duplicate billing authority.

## Ownership and spending

Domain ownership is an explicit registrar assignment. A hosting service's domain property alone does not authorize registration, observation, billing, or renewal. Design coexistence with other registrar extensions before offering shared navigation or adopting existing domains.

Represent paid, custom-priced, and explicitly complimentary domains separately. A zero retail price alone does not authorize registrar spending. Verify settled funding, current ownership, server/account binding, and prior-operation outcome before a write. Keep money in integer minor units or another exact representation; distinguish retail prices, quoted provider cost, and confirmed final charge.

An unknown result remains unknown until authoritative evidence resolves it. Provider timeouts and malformed replies are not definite failures. Retrying a renewal can buy another year; queue retries must not repeat uncertain writes.

Use native encrypted settings for credentials, explicit test/live account binding, and deliberate live activation. Default processing off. Copied databases must not accidentally activate live spending.

## Packaging and retained data

The planned ZIP has a single `NameSilo/` wrapper, not `extensions/Servers/NameSilo/`. Verify the supported Paymenter uploader and lifecycle hooks before claiming installation compatibility. Include the distributable MIT license. Exclude documentation placeholders from release archives once a package builder exists.

Use extension-owned tables and identifiers, with a reviewed schema. Preserve registrations, invoices, and operation evidence through upgrades and uninstall. NameSilo must never write Enom-owned tables or take over existing Enom domains implicitly.

## Attribution

New scaffold material is MIT-licensed to Autonomic Engineering. Future source adapted from Enom must retain its original MIT notices, including Beco Technologies attribution where applicable, with source revision and contribution provenance recorded. No runtime source has been copied into this scaffold.
