# Retained ZIP upgrades

`test_installed_package_previous.py` runs in the default native SQLite category.
It archives an unmodified Git commit's `Domains/` directory, builds and verifies
its ZIP, installs through Paymenter's native uploader, seeds retained evidence,
then removes only the runtime files, uploads the current ZIP through native Install
on the retained database and verifies in a new PHP process. The default
baseline is `HEAD~1`; set `DOMAINS_UPGRADE_FROM=<rev>` to qualify a deployed or
published commit. The runner forwards this variable to isolated workers. Invalid
or unavailable commits fail; nothing is fetched. Choose an installable revision
with APIs compatible with the synthetic commerce fixture.

The receipt prints the resolved commit, archive hashes, whether Domains bytes
are identical, baseline row counts and scenario wall time. Identical Domains
still run. Four PHP phases (install, seed, upload, verify) keep the standing
scenario within the native runner's existing 120-second command timeout; each
phase has a 45-second timeout. Dependency copying and resource waits also count
against that outer bound.

The baseline's own code creates accounts, encrypted native settings, accepted
catalogue/prices, seller policies, checkout ledger/reservations, payment and
credit history. Where supported, it also seeds a staff review/replay key,
encrypted archive and disabled watch/adoption/notice evidence. The shared
lifecycle comparison checks every prior row and its original raw column values, including multiplicity; additive columns/default rows are allowed
and account `updated_at` remains excluded. Unqualified table listing and explicit
snapshot guards prevent an empty retained comparison on SQLite.
Verification runs before a repeated upgrade: complete schema, original migration
records as an unchanged prefix, exactly-once filename order, one scheduler and
navigation registration, installed public GET rendering and the staff setup page.
No source runtime autoloader or template replacement is installed. The pristine
upstream source has no compiled frontend assets; a fixture Vite hot-file supplies
synthetic asset URLs for HTML rendering only, with no asset delivery claim.

Keep `test_installed_package_upgrade.py`'s pinned `e7881cf` scenario: it exercises
seven-to-current additive migrations plus reinstall/uninstall/server-delete
retention even when the rolling parent has no Domains changes. Its sole historical
fixture patch adds the superseded observation index; it is separate from the
unpatched standing baseline and is not evidence for that historical index upgrade.

The opt-in MariaDB category currently exercises native schema fresh/reinstall/
upgrade, not installed ZIP upgrades. This SQLite scenario adds no MariaDB claim,
provider calls, concurrent locking or production deployment acceptance.

`conversion-test.php` checks the gate before journal creation, refuses incomplete conversion, then verifies that repeated completed checks make no database queries.
Conversion checks compile the journal definition with the offline MySQL grammar, retain a summary over 64 KB across repeated installs, read bare systemd template definitions with `cat`, and require concrete stopped instances before state inspection. SQLite cannot distinguish MySQL TEXT from LONGTEXT.
Package checks cover the exact serialized ZIP limit before artifact creation, portable case-collision fixtures, in-memory non-UTF-8 paths, archive/member/total verification limits, and CLI verification against the adjacent manifest.
