# Development tools

- `python3 scripts/bootstrap.py --download` builds the pinned PHP suite image, including native modules.
- `python3 scripts/bootstrap.py` (also accepts `--native`) checks for the prepared image offline.
- `scripts/test.py`: registered offline suites and lint; optional `--suite`, private JSON receipts.
- `scripts/php-test`: runner-only wrapper disabling network/process functions.
- `scripts/affected.py`: tests a change affects and their CI shards, from `tests/affected.json`.
- `scripts/native.py`: pinned pristine dependency guard and disposable fixture copy; no downloads.
- `scripts/build.py`: synthetic package boundary support; current foundation lacks an entry point.

See [verification](../docs/testing.md) for native dependency selection, checksum provenance and
coverage limits. Package tests are not native installation acceptance.
