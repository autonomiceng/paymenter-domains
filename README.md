# NameSilo for Paymenter

A planned [Paymenter](https://paymenter.org) extension for selling and managing domain names through NameSilo.

**Scaffold only.** There is no extension implementation, installable ZIP, release, or verified Paymenter compatibility yet. Registration, billing, and domain management are not implemented.

The repository follows the layout of [Paymenter Enom](https://github.com/autonomiceng/paymenter-enom). Development will use Paymenter's native server extension, services, invoices, and administration interfaces. Its initial reference baseline is Paymenter 1.5.9; compatibility must be established during implementation.

## Layout

```text
NameSilo/   Reserved extension directory and distributable license
docs/       Public development conventions and verification guidance
scripts/    Reserved for offline checks and native ZIP packaging
tests/      Reserved for offline tests and sanitized fixtures
.github/    CI, contribution templates, and dependency update configuration
```

Read [CONTRIBUTING.md](CONTRIBUTING.md), [extension conventions](docs/paymenter-extension-conventions.md), and [verification guidance](docs/testing.md). CI currently checks repository hygiene only; it does not test an extension.

## License

[MIT](LICENSE), copyright 2026 Autonomic Engineering. NameSilo and Paymenter are trademarks of their respective owners; this is an independent project.
