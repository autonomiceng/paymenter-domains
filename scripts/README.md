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

## Hosted Ubuntu PHP suites

On GitHub-hosted Ubuntu 24.04, run `bin_dir=$(bash scripts/hosted-php-userns.sh)` before
starting the delegated service. Use `--unshare /usr/bin/unshare` if startup also calls
util-linux `unshare`. The runner user needs passwordless sudo and the system `apparmor_parser`
when Ubuntu restricts unprivileged user namespaces. The helper loads executable profiles for
root-owned copies under `/run` and prints that directory, or prints nothing when the restriction
is off. Pass `--setenv="PATH=${bin_dir:+$bin_dir:}$PATH"` to `systemd-run` and invoke the binaries
by name. AppArmor attaches each profile by path.
