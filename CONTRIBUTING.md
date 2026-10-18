# Contributing

This project currently contains repository scaffolding only. Agree on implementation scope before adding extension code.

Read [extension conventions](docs/paymenter-extension-conventions.md) and [verification guidance](docs/testing.md). Keep runtime in `NameSilo/`, offline tests in `tests/`, and development tools in `scripts/`. Record design decisions and evidence in `docs/` once implementation begins.

Use native Paymenter billing and extension interfaces. Changes involving registrar spending must cover funding, ownership, concurrent jobs, price changes, definite failures, and uncertain outcomes. Domain mutations require authorization and bounded, sanitized provider input/output. Keep internal costs out of unauthorized views and all secrets out of logs.

Use synthetic examples, `example.com`, and documentation addresses in public material. Keep `.local/`, `.scratch/`, credentials, customer data, and operational evidence untracked. Before committing, inspect the full staged diff and file list; `.gitignore` does not protect files added with `git add -f`.

Open focused pull requests with the problem, resulting behavior, verification performed, and remaining limits. Use Conventional Commits and the maintainer-configured author, committer, and signing settings. Preserve existing MIT notices in adapted source.

The current CI checks repository hygiene only. As runtime is introduced, add meaningful provider-contract, native integration, UI, and package checks. Describe unrun checks explicitly; passing scaffold CI does not establish extension compatibility or provider acceptance.
