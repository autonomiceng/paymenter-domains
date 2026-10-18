# NameSilo extension work

The standalone API client is in progress and not usable as an extension yet. Before changing provider behavior, read `docs/design/api-contract.md` and `docs/design/parity.md`; preserve unknown outcomes and acceptance gates. Reads alone are dispatchable; purchase mappings have no transport path. Runtime, billing and UI integration require a scoped implementation task.

Before implementation, read `docs/paymenter-extension-conventions.md` and `docs/testing.md`. In the maintainer's local checkout, also read `.local/IMPLEMENTATION-HANDOFF.md` when present. Read `.local/REGISTRAR-ALTERNATIVES.md` when reconsidering the provider. These ignored files are local research, are absent from public clones, and must stay out of commits and packages. If absent, use public docs and the active task to establish scope.

Runtime belongs in `NameSilo/`. Keep Paymenter core, authentication, bundled gateways/server integrations, themes, and native schema upstream. Use native services and invoices. Registrar spending requires verified funding, current ownership, and a resolved prior outcome. Preserve invoice history, complimentary renewal cycles, and uncertain operations. Coexistence with another registrar must assign exactly one renewal authority per domain.

Provider credentials, customer/contact data, transfer codes, and raw account responses belong in local private storage. Use synthetic public fixtures. Offline work is the default. Authenticated provider calls, account funding, staging/production access, deployments, migrations, and releases need explicit task authority. The API foundation task authorizes offline client implementation and synthetic tests only.

Run `scripts/test.py` before committing. Use the configured shared Git identity and signing settings. Preserve upstream copyright and contributor attribution when adapting MIT code. Request implementation code review with GPT-6.1 Sol high; architecture changes need Opus 5.5 high at standard speed. Review findings and limits belong in a dated handoff; public release claims must match actual evidence.

When interacting with Cloudflare, use the cf CLI unless the project has a Wrangler configuration file.
