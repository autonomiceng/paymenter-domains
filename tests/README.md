# Offline tests

Run `scripts/test.py` after [bootstrap](../docs/testing.md). Every `*-test.php` under this directory
is discovered; no missing-suite success. `bootstrap.php` supplies the standalone autoloader and
strict assertions. The runner suite verifies pinned PHP and disabled network/process entry points.

Provider JSON fixtures must be labelled **synthetic** and use example.com/.net/.org/.us,
documentation IPs and placeholder keys. Fake transport/cURL functions inspect requests without
network access. Tests never load credentials, an installed Paymenter app or customer data.

No native Paymenter, database, billing, UI or provider acceptance is claimed. Add those harnesses
with their implementation; keep ordinary runtime suites offline.
