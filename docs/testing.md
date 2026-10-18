# Verification

Run `python3 scripts/bootstrap.py --download` once, then `scripts/test.py` fully offline.
The pinned runtime is PHP **8.4.26**, Debian `8.4.26-1~deb13u1`, Linux x86_64. Bootstrap verifies
SHA-256 for every archive against the committed lock; hashes were checked against Debian's
[security package index](https://deb.debian.org/debian-security/dists/trixie-security/main/binary-amd64/Packages.xz)
on 2026-10-17. Cached archives are verified and re-extracted before each test run. A missing or
corrupt archive fails; the runner never downloads. `PHP_RUNTIME_ARCHIVE_CACHE` may point to an
existing Enom PHP archive cache; files are copied and verified, never modified in that cache.

Requires Python 3, dpkg-deb and compatible system libraries (Ubuntu 24.04 x86_64: libxml2,
libargon2-1, libsodium23, libcurl4t64). PHP CLI/common/curl packages are extracted locally,
not installed system-wide. No system PHP, Composer or JavaScript dependencies are used.
Only PHP is pinned here; system library versions follow the runner OS.

The runner lints all PHP and executes every `tests/**/*-test.php`. A clean environment and PHP
settings disable URL streams, cURL execution, network sockets, process launching and mail;
`zend.exception_ignore_args=1` keeps sensitive function arguments out of exception traces.
Synthetic cURL functions may inspect transport configuration without a socket. These are runtime
guards, not an OS security sandbox for hostile test code. A suite failure, timeout (60 seconds),
missing suite or changed runtime source fails the run. Private `/tmp/namesilo-tests-*/receipt.json`
receipts preserve command exits and source hashes. No registrar account or credentials are needed.

CI retains repository hygiene and runs bootstrap plus this runner without secrets. Downloads
belong only to bootstrap; ordinary suites are offline. Passing tests establish local protocol
interpretation, not registry acceptance, Paymenter installation or concurrency correctness.

## Reference and deferred coverage

Adapted from Enom `d2c55e2` scripts/test.py and scripts/php-test. Its bootstrap accepts system
PHP 8.3/8.4, verifies the official Paymenter Git remote/tag v1.5.9 and pristine files, installs
Composer dependencies from the lock and fingerprints manifests for cache reuse. It does **not**
ship a hash-pinned PHP downloader or lock the tag to a commit hash. This slice adds an explicit
PHP archive/hash lock instead of describing that reference as stronger than it is.

Paymenter 1.5.9 remains the integration target. No Paymenter bootstrap or native harness is
needed for a standalone provider client, so none is loaded or downloaded. Future native suites
must pin the upstream commit and lockfile, verify a pristine disposable application with vendor,
and reject installed apps, .env files and cached configuration. Add billing/ownership, concurrency,
UI and package checks when those features exist. MySQL/MariaDB behavior needs separate evidence.

See [API gates](design/parity.md). Sandbox calls, real purchases and environment access require
separate task authority. No provider acceptance or installable package exists.
