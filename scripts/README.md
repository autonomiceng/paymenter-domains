# Development tools

- `python3 scripts/bootstrap.py --download`: cache pinned PHP archives; network only on explicit bootstrap.
- `python3 scripts/bootstrap.py --native`: verify/cache additional native modules, without downloading.
- `scripts/test.py`: registered offline suites and lint; optional `--suite`, private JSON receipts.
- `scripts/php-test`: runner-only wrapper disabling network/process functions.
- `scripts/native.py`: pinned pristine dependency guard and disposable fixture copy; no downloads.
- `scripts/build.py`: synthetic package boundary support; current foundation lacks an entry point.

See [verification](../docs/testing.md) for native dependency selection, checksum provenance and
coverage limits. Package tests are not native installation acceptance.
