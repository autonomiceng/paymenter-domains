# Domains for Paymenter

An in-development [Paymenter](https://paymenter.org) extension for selling and managing domain names through supported registrars.

**API client in progress, not usable yet.** A standalone bounded API client and synthetic offline tests exist. There is no Paymenter entry point, installable ZIP, release, billing or domain-management integration, or verified provider/Paymenter compatibility.

The repository follows the layout of [Paymenter Enom](https://github.com/autonomiceng/paymenter-enom). Development will use Paymenter's native server extension, services, invoices, and administration interfaces. Its initial reference baseline is Paymenter 1.5.9; compatibility must be established during implementation.

## Layout

```text
Domains/    Registrar foundations and distributable license
docs/       Public development conventions and verification guidance
scripts/    Pinned PHP bootstrap and offline test runner
tests/      Synthetic provider and transport tests
.github/    CI, contribution templates, and dependency update configuration
```

Read [CONTRIBUTING.md](CONTRIBUTING.md), [extension conventions](docs/paymenter-extension-conventions.md), and [verification guidance](docs/testing.md). CI checks repository hygiene and offline PHP suites. See the [API contract](docs/design/api-contract.md) and [parity gates](docs/design/parity.md). No account or sandbox has been exercised. All purchase builders are non-dispatchable mappings; renewal also awaits flag/restore clarification; premium eligibility, funding, ownership and safe execution admission remain caller/integration gates. No automatic retries are provided.

## License

[MIT](LICENSE), copyright 2026 Autonomic Engineering. NameSilo and Paymenter are trademarks of their respective owners; this is an independent project.
