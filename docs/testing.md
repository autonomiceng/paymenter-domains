# Verification

## Current scaffold

The `Repository checks` workflow checks required public files, MIT license correspondence, whitespace, and tracked paths that should remain private. It runs on pushes, pull requests, and manual dispatch with a read-only GitHub token. It has no provider credentials or deployment steps.

There is no PHP entry point, runtime test suite, build script, package, or release. Successful CI therefore proves repository hygiene only.

## Checks to add with implementation

- Provider contracts: documented response codes, partial success, money, contacts, term limits, DNSSEC/glue, privacy, renewal flags, and unknown outcomes.
- Native integration: verified funding, owner/permission checks, idempotent job admission, invoice preservation, refunds to native credit, and coexistence with other registrars.
- Concurrency: duplicate jobs, interrupted writes, repeated payment notifications, and reconciliation. In-memory SQLite alone does not prove MySQL/MariaDB locking behavior.
- UI: authenticated and guest routes, CSRF, escaping, retained input, internal-cost visibility, staff permissions, and keyboard/narrow-screen behavior.
- Packaging: one native wrapper, deterministic inventory, correct metadata/license, no private/dependency/core files, and data-retaining install/upgrade/uninstall behavior.

Use a pristine explicitly selected Paymenter development checkout, disposable databases, and injected provider responses. Keep ordinary tests offline and fail if required test dependencies are missing. Reassess supported versions before making compatibility claims.

Request separate authority for sandbox API calls, real domain purchases, payment testing, or environment access. Record precisely what each acceptance check proves and what remains unverified.
