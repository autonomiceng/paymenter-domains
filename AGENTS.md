# Domains extension work

One MIT Paymenter server extension that sells and manages domains through Enom and NameSilo.

## Where code lives

- `Domains/` is the runtime. [Layout](docs/design/layout.md) names the feature folder that owns each kind of
  code, which folders exist today and where new code goes. Put new classes in the owning feature folder, not in
  `Services/`, `Data/` or `Admin/Support/`.
- Provider protocols live in `Domains/Registrars/<Provider>/`; the wire clients in `Registrars/<Provider>/Api/`
  name only their own namespace.
- A directory holds at most 40 PHP files of its own. `tests/test_directory_sizes.py` and
  `tests/test_registrar_api_imports.py` enforce both rules; their lists of today's exceptions may only shrink.
- Offline tests are in `tests/`, development tools in `scripts/`, design notes in `docs/design/`.

## Tests

- Setup and limits: [verification](docs/testing.md). Build the suite image once with
  `python3 scripts/bootstrap.py --download`.
- One file: `scripts/test.py --only tests/<file>`. Native tests also need `PAYMENTER_TEST_APP` set to a pristine
  Paymenter 1.5.9 checkout.
- The tests a change affects: `python3 scripts/affected.py --base origin/main --suite <suite>` for each suite in
  `tests/suites.json` plus `native`; pass the listed paths to `--only`. Add a test the map cannot find through
  imports or file names under `explicit` in `tests/affected.json`.
- Register a new non-native test in its `tests/suites.json` category.

## Provider and upgrade rules

Before changing provider behavior, read [API contract](docs/design/api-contract.md) and
[parity](docs/design/parity.md); preserve unknown outcomes and acceptance gates. Before implementation, read
[extension conventions](docs/paymenter-extension-conventions.md). Runtime, billing and UI integration require a
scoped implementation task.

Keep Paymenter core, authentication, bundled gateways and server integrations, themes and native schema upstream.
Use native services and invoices. Registrar spending requires verified funding, current ownership and a resolved
prior outcome. Preserve invoice history, complimentary renewal cycles and uncertain operations. Coexistence with
another registrar must assign exactly one renewal authority per domain.

Paymenter must stay upgradable without extension releases. Follow
[Keeping Paymenter upgradable](docs/paymenter-extension-conventions.md#keeping-paymenter-upgradable): no container
rebinding, native route remaps, global hooks or version gates.

## Data and authority

Provider credentials, customer and contact data, transfer codes and raw account responses belong in local private
storage. Use synthetic public fixtures. Ignored `.local/` research files may exist in a maintainer checkout; keep
them out of commits and packages. Offline work is the default. Authenticated provider calls, account funding,
staging or production access, deployments, migrations and releases need explicit task authority.

Use the configured Git identity and signing settings. Preserve upstream copyright and contributor attribution when
adapting MIT code. Public release claims must match actual evidence.
