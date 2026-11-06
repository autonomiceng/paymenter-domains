# Prepared renewal test setup

Selected renewal component tests restore `tests/fixtures/renewal-initialized.json`, an empty SQLite
installation produced by the real `Installation::install()` lifecycle. It contains no accounts or operations.
Restoration checks the Domains PHP digest and Paymenter pin, then verifies every installed schema,
active authority and SQLite foreign keys. Native migrations, checkout, events, funding and dependency/runtime
guards still run. Installation lifecycle tests continue to run the installer itself.

After a Domains PHP change, regenerate through the [pinned native environment](testing.md#offline-native-fixture) into a fresh directory
under `TMPDIR`, review the output, and copy `renewal-initialized.json` into `tests/fixtures/`:

```sh
suite-slot --quick python3 scripts/render-reminders.py "$RENEWAL_SETUP_DIR" tests/Support/build-renewal-initialized.php
```

`renewal-initialized-fixture-test.php` also runs the normal installer on the restored state and verifies
its authority remains unchanged. A stale snapshot fails; tests never rebuild or accept it automatically.
