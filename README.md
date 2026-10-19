# Domains for Paymenter

An in-development [Paymenter](https://paymenter.org) extension for selling and managing domain names through supported registrars.

**Provider writes ship held.** The native runtime ZIP, installation, retained-data lifecycle and synthetic offline scenarios target Paymenter 1.5.9. This is development evidence, not a released or live-provider compatibility claim. No provider account or sandbox has been exercised here.

The repository follows the layout of [Paymenter Enom](https://github.com/autonomiceng/paymenter-enom). It uses Paymenter's native server extension, services, invoices and administration interfaces.

## Operator enablement

Fresh installation creates no registrar account or sale offer. Verified credentials alone leave account processing off. The runtime supplies no accepted provider executor; registration, transfer, renewal and management write features independently default off. No setting enables these write features; they change only through a reviewed deployment composition after the relevant provider/account path is independently proven.

Enablement requires trusted deployment composition for the exact account binding and explicit staff review in **Domains → Registrar setup**. Catalogue acceptance records staff attestation of independently verified exact terms and annual renewal support in the staff audit. Registration and transfer offers for an ending stay withdrawn unless that acceptance also marks registry requirements supported. Processing requires a fresh confirmed review, accepted runtime composition and shared cache/session/queue workers. Neither catalogue review nor credential verification alone enables processing, publishes prices, selects a seller or enables sales. Registration also needs eligible current prices, explicit TLD/seller availability and the registration feature/acceptance in that reviewed composition.

Transfers retain their separate default-off write feature, accepted ending/precheck evidence, funded native invoice and owner-supplied transfer code before its deadline. Renewals retain their separate default-off feature and accepted ordinary renewal policy (grace is an additional explicit opt-in); scheduled billing (`domains_billing_enabled`) and manual renewal requests (`domains_manual_renewals_enabled`) also default off. Redemption restores are always refused; this build has no restore path. These gates preserve existing obligations and uncertain history; pausing or disabling sales grants no retry, refund or registrar fallback.

Build with `python3 scripts/build.py --output-dir /fresh/output`, then verify the ZIP with `python3 scripts/build.py --verify /fresh/output/paymenter-domains-0.1.0.zip` before native upload. Use a trusted artifact source: checksums establish integrity, not publisher identity. Refresh PHP workers after replacing runtime files. Uninstall retains domain and billing evidence; retained registrar accounts cannot be deleted.

## Layout

```text
Domains/    Native runtime and distributable license
docs/       Public development conventions and verification guidance
scripts/    Pinned PHP bootstrap, offline test runner and separate conversion tooling
tests/      Synthetic provider, transport and native package acceptance
.github/    CI, contribution templates, and dependency update configuration
```

Read [CONTRIBUTING.md](CONTRIBUTING.md), [extension conventions](docs/paymenter-extension-conventions.md), and [verification guidance](docs/testing.md). See the [API contract](docs/design/api-contract.md), [runtime boundaries](docs/runtime.md), and [parity gates](docs/design/parity.md). Offline native SQLite acceptance does not establish shared-database locking, real provider acceptance or a production deployment.

## License

[MIT](LICENSE), copyright 2026 Autonomic Engineering. NameSilo and Paymenter are trademarks of their respective owners; this is an independent project.
