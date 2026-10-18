# Verification

Run `python3 scripts/bootstrap.py --download` once, then `scripts/test.py` fully offline.
PHP **8.4.26**, Debian `8.4.26-1~deb13u1`, Linux x86_64 is pinned by archive SHA-256.
CLI/common/curl hashes were checked against the Debian security index on 2026-10-17.
Optional native sqlite3/mbstring/intl/xml and ICU hashes are pinned from the existing local
reference archives; their upstream index provenance has not been independently rechecked.
Every run verifies and re-extracts archives; missing/corrupt cache fails without downloading.
`PHP_RUNTIME_ARCHIVE_CACHE` copies verified archives from another cache without changing it.
Python 3, dpkg-deb and compatible system libraries are required; no system PHP or Composer runs.

## Suites and receipts

`tests/suites.json` registers provider, contracts, native, UI, runner and package categories.
All `*-test.php` and `test_*.py` paths must be registered exactly once and exist.
Detected `*-test.cjs` files are refused until a JavaScript runner is implemented.
Lint includes every runtime/test PHP file. Unknown, missing or explicit unimplemented suites fail.
Default runs execute all implemented categories except native when no dependency is supplied;
empty categories are reported as unimplemented. `--suite` selects categories; receipts disclose
omissions. No UI suite exists yet. Synthetic provider tests do not establish provider acceptance.

Clean environment and PHP settings disable URL streams, cURL execution, sockets, process launch
and mail. Exception arguments are hidden by default; redaction cases explicitly enable them.
Synthetic namespaced cURL functions inspect configuration without sockets. These are runtime
guards, not an OS sandbox. SQLite is the only loaded PDO driver in native runs; no database sockets.
Failure, timeout (60 seconds), or changed runtime bytes stops the run. Timeout termination
kills the command process group, including nested children, before cleaning up the fixture. Private
`/tmp/domains-tests-*/receipt.json` records command exits, partial outputs, hashes, omitted and
unimplemented categories. Setup failures also leave a receipt; final dependency guard failures preserve command results. Laravel suites must explicitly
mark completion because framework exception rendering can otherwise return status zero.

## Offline native fixture

Provide an existing pristine development checkout with vendor, not an installed application:

```sh
PHP_RUNTIME_ARCHIVE_CACHE=/path/to/verified/php-archives \
PAYMENTER_TEST_APP=/canonical/path/to/paymenter-1.5.9 scripts/test.py
```

Native archives may be seeded with `python3 scripts/bootstrap.py --native`; downloads still
require the separate `--download` flag. The dependency guard pins Paymenter v1.5.9 commit
`ee729f7d99ac18942625b79f3f93d8d8b21255e8` and lock SHA-256
`fd6bdeb5557b3af0976d5688d92081abc6f7c3235806a6c4b77f4b8bca9186be`.
It checks every upstream tracked byte against that commit, rejects .env/cached config/symlinks,
and checks installed package names/versions/dist references against the lock. The sole permitted
source edit is the exact app.version development→1.5.9 substitution; the disposable export uses
original upstream bytes. Vendor byte fingerprints prove source immutability during the run,
not independent archive authentication of every dependency. No source dependency is written.

The runner copies source and vendor to a private disposable fixture, uses synthetic environment
values, migrates an in-memory database, blocks stray HTTP, and removes the copy afterward.
Native coverage includes upstream schema/model/transaction/HTTP guards, manually invoked Domains
foundation migrations and standalone retained-schema verification, global reservation/rollback,
and synthetic account/site/credential binding guards. There is no Domains entry point, billing,
production registrar identity verifier or installed extension. These are fixture-only checks.
SQLite alone cannot establish MariaDB locking, concurrent checkout or financial correctness.
CI uses cached pinned PHP and runs standalone categories; native checks need an explicitly supplied
local dependency and are not claimed by CI. No dependency checkout/download/bootstrap is automatic.

## Packaging and limits

Package support is adapted from Enom `d2c55e2` scripts/build.py and synthetic package tests.
Synthetic entry points exercise deterministic ZIP/member SHA manifests, license equality,
canonical output, no overwrite, private-file/path and symlink refusal. The actual foundation
**cannot build a release ZIP**; final native upload/install/reinstall evidence is deferred.
Runner/native fixtures adapt that revision's scripts/test.py, tests/test_runner.py,
tests/paymenter-test-app.php and tests/enom-native-app.php, preserving MIT notices.

Passing offline tests never establish real writes, sandbox acceptance, transfer completion,
SMTP delivery, browser accessibility, installed ZIP compatibility or production pricing.
See [API gates](design/parity.md). Provider/environment acceptance requires separate authority.

Enom protocol tests (slice 10) require DOM in standalone/provider runs as well as native runs.
The already pinned XML archive is now part of the base offline runtime; `php-test` loads DOM
without loading the other native fixture extensions. Missing archives still fail without download.
Its upstream hash provenance remains the locally pinned evidence described above.

Enom named read normalization is exercised by `tests/enom-read-test.php` with synthetic XML and
a synthetic accepted-binding factory. Price absence differs from failed reads; ownership, expiry
precision, unknown flags, registry requirements and contact redaction have no provider dependency.

NameSilo slice 13 uses `namesilo-read-test.php` for bound deadline reads, explicit annual account
price acceptance, null premium, date-only expiry and bounded unresolved history candidates.
`namesilo-pricing-test.php` exercises the real accepted-binding and quote→rate→snapshot path in
native SQLite; failed quotes retain price revisions/freshness. Fixtures are synthetic only.

Enom slice 12 adds `enom-money-test.php` for exact source quote/renewal/order-charge evidence
and `enom-pricing-test.php` for its real CatalogueRate → PriceSnapshot persistence, failed-read
retention and below-cost renewal behavior. All transport is synthetic; no provider calls occur.

Slice 14 `catalogue-sync-test.php` exercises both real read adapters through shared acceptance,
import and seller eligibility: encrypted revisions/revocation, one NameSilo batch, compound suffixes,
live term narrowing, positive no-price, omission/failure retention and non-selling holder renewals.
All wire transports are synthetic; native SQLite does not establish provider or MariaDB acceptance.
