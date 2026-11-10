# Native tests in CI

`.github/workflows/native.yml` caches the pinned Paymenter v1.5.9 app, keyed on the commit and lock hash in
`scripts/native.py`. On a miss it clones v1.5.9 and runs `composer install` from its lock. It then runs
`scripts/test.py --only` over parallel shards.
Pull requests run the native tests `scripts/affected.py` picks for their change; a nightly run on main runs every
native test and never blocks a pull request. `tests/affected.json` maps changes to tests:

- Affected: a changed test, tests that use a changed file (class name, import, include or file mention) within
  `depth` hops, and `explicit` tests for the file.
- Widened: a `core` file adds the whole suite; a file that reaches more than a quarter of all tests within two hops
  adds every test within two hops.
- Capped at 120 tests per pull request, affected tests first, then the nearest widened ones. The plan step names
  what the cap left out; the nightly run covers it. At most six shards of about 20 tests run at once.

Run `python3 scripts/affected.py --base main` to see a branch's selection, or `--files PATH...` for given paths.
