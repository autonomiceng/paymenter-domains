# Contributing

The standalone API foundation is in progress; Paymenter integration remains unimplemented. Agree on implementation scope before adding billing, jobs or UI.

Read [extension conventions](docs/paymenter-extension-conventions.md) and [verification guidance](docs/testing.md). Keep runtime in `Domains/`, offline tests in `tests/`, and development tools in `scripts/`. Record design decisions and evidence in `docs/` once implementation begins.

Use native Paymenter billing and extension interfaces. Changes involving registrar spending must cover funding, ownership, concurrent jobs, price changes, definite failures, and uncertain outcomes. Domain mutations require authorization and bounded, sanitized provider input/output. Keep internal costs out of unauthorized views and all secrets out of logs.

Use synthetic examples, `example.com`, and documentation addresses in public material. Keep `.local/`, `.scratch/`, credentials, customer data, and operational evidence untracked. Before committing, inspect the full staged diff and file list; `.gitignore` does not protect files added with `git add -f`.

Open focused pull requests with the problem, resulting behavior, verification performed, and remaining limits. Use Conventional Commits and the maintainer-configured author, committer, and signing settings. Preserve existing MIT notices in adapted source.

Run `scripts/test.py` before committing. CI checks repository hygiene and synthetic offline PHP suites. Add native integration, UI and package checks with those features. Describe unrun checks explicitly; synthetic tests do not establish extension compatibility or provider acceptance.
