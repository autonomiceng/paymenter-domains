# Development tools

- `python3 scripts/bootstrap.py --download`: cache and verify pinned PHP archives; network only here.
- `PHP_RUNTIME_ARCHIVE_CACHE=/path/to/cache python3 scripts/bootstrap.py`: seed from verified local archives.
- `scripts/test.py`: offline PHP lint and all `tests/**/*-test.php` suites; JSON receipt path on completion.
- `scripts/php-test`: internal wrapper used by the runner, with network/process execution disabled.

See [verification](../docs/testing.md) for supported OS, checksum sources, failure behavior and
Paymenter coverage limits. The package builder and native Paymenter harness remain deferred.
