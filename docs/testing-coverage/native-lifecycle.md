# Native lifecycle and packaging coverage

## Native paid-loop qualification and closed-order payment review

The same offline matrix runs with the retained paid-invoice adapter and with a test-only
unbound native processor. It covers mixed hosting/domain/Credit, early synchronous and
serialized delayed native jobs, duplicate wakes, contact recovery, multi-day transfers,
refund interruption, re-payment/reactivation, capability loss and scheduler recovery.
Existing acquisition alerts distinguish new payment activity and expose a staff filter and
permission-aware invoice/request links. Ordinary completed cancellation and retained refunded
payments stay quiet. Publication initializes approved product mail once and preserves later
staff changes, including null and blank. Native paid-loop dependencies are test-only tripwires.

## Seller-switch lifecycle command budget

Seller-switch lifecycle runs one native command per registrar/acquisition action through
`Support/seller-switch-lifecycle.php`; the original command keeps the two native checkout
transaction guards. Each original register/transfer schedule still runs once for each
registrar: stale carts after switching back, canonical contention, unpaid funding, fresh
reads, durable seals and unknown workers. Native checkout, funding and retained-byte
assertions are unchanged. Independent processes bound accumulated commerce rows. These
synthetic SQLite checks do not establish provider or concurrent-database acceptance.

## Acquisition retirement command budget

Acquisition retirement runs as independent native commands for cron/wake-up/cancellation
(`acquisition-retirement-test.php`), uncertain money/send/sibling/age refusals
(`acquisition-retirement-refusals-test.php`) and staff review/audit/late-money/modals
(`acquisition-retirement-staff-test.php`). `Support/acquisition-retirement.php` shares
unchanged native setup. Related released-name and shared-invoice scenarios stay together;
real native checkout, cron, cancellation listener, paid activation and Filament actions
remain exercised. These synthetic SQLite checks do not establish provider or concurrent
shared-database acceptance.

## Native paid processing after override removal

Both historically named baseline/candidate entrypoints now run the same native-only matrix.
The test switch and self-rebinding are gone; composition asserts the processor is unbound
and resolves to the native class. Existing paid/cron, cancellation, identity and projection
coverage qualifies the removal together. No upstream hashes were changed.

## Native editor removal

Native server/settings, lifecycle, presenter and fresh-boot fixtures qualify unchanged
Create Server, Edit Server and Edit Service routes, real `route:cache`, and native pages
opened before a compiled-route change and hydrated afterwards. Scoped guards still
refuse unsafe identity and lifecycle writes; ordinary saves and paid hooks continue.
Native Edit Server Delete is exercised for both registrars: refusal is handled with a
staff notice, preserves settings/binding/server, and retains the native processing pause.
An unrelated native server still deletes. Direct model deletions still throw.
The upgrade tripwire pins native delete dispatch and Filament's handled Halt behavior.
The runner hygiene suite requires the conventions exceptions section to be empty.
Old Secure-class snapshots and caches require drained deployment, cache rebuild and page
reload; offline native fixtures do not establish installed-theme or provider acceptance.

## Owner retry input and list projections

The paired owner-management suites exercise normalized native POST input, allowlisted contact
and role retention, field errors in only the failed contact step, escaped redisplay and domain
scoping. Nameserver, lock and setup retries retain their nonsecret values; confirmations and
injected private fields are excluded. Owner-action tests assert code failures flash no input.
Record-error suites retain bounded public DNS/SRV/DS/glue drafts without session flashing.
Real active holdings compare full and list DTO facts and assert zero settings queries for list
projections. These offline fixtures do not establish provider or installed browser acceptance.

## Installed-package scenario commands

`test_installed_package.py` runs default-held registration and all three unsafe-archive
refusals. `test_installed_package_fresh.py` runs the current-build lifecycle;
`test_installed_package_upgrade.py` builds the pinned previous revision, applies the
existing observation-index fixture adjustment and runs the previous-to-current lifecycle.
Both lifecycle commands retain install, seed, repeated upload/verify, server-delete/verify
and file-removal/reinstall/verify phases. Each command gates on 1.5 GiB available memory
and owns a temporary application/database under the runner fixture parent.
Each prints only its scenario's JSON evidence; together they retain every original key,
including both archive hashes and previous-fixture provenance. Offline native SQLite
acceptance does not establish provider, installed-theme or concurrent-database acceptance.

## Native service refusal feedback

`native-service-refusal-test.php` and `native-service-refusal-holding-test.php` run
the real native Edit Service page with each guard boot order. Manual price Save,
each native identity field (product, plan, user, quantity, currency, coupon), and
Delete return handled danger notices with unchanged service, ledger, invoice, settings
and processing state. The Active Delete case checks the default terminate-server
branch against the real Domains no-op with native auditing enabled: only its native
terminate audit is added and the same refusal is handled. No workflow or registrar
job is queued; only the fixture's existing native checkout after-response callbacks
replay.
Recalculate Price exercises the actual hint action: the native
success notice and draft value remain, stored state stays unchanged, and Save of the
changed draft is handled as a price refusal. Hosting saves and deletion still work.

Non-UI writes keep the exact original DomainException type/message. Scoping probes
exercise an unrelated Livewire page, a different native record, unrelated mounted
actions, Save during Delete and deletion without the Delete action. Mutation probes
remove these checks independently and must fail the same acceptance matrix. Offline
rendered notifications cover price, recalculated-price Save, identity and Delete at
1440/1024 in dark/light; they do not establish installed-theme or browser interaction
acceptance. Native Active expiry validation remains an accepted upstream limitation.

Public notice planner: `watch-notice-plan-test.php` covers expiry-window and DNS-episode policy
with synthetic current/stale/revision inputs; persistence, delivery and installed acceptance remain separate.

Watch notice delivery: independent native channels, queued versus uncertain states, render-before-claim,
missing-only template installation, native and public preferences, urgent priority, owner/role changes,
retirement, managed proof/claim continuity, accepted-only restoration and stale predecessor interleaving.

Watch notice overflow: a conflicting profile outside the observed source cohort refuses fresh DNS proof and reserved mail.

## MySQL/MariaDB schema identifiers and native lifecycle

`schema-identifiers-test.php` compiles the real migration/create/alter and retained-reference
Blueprints with Laravel's MySQL grammar, without a MySQL server or PDO connection. It checks
all quoted identifiers, every verifier call site and every migrated table, with index prefixes
on/off and empty, short and maximum-fitting table prefixes. This runs in the ordinary native
suite. It caught both the long verifier reference names and the 65-character watch-observation
index; that index now has a table-derived `_facts_idx` name. Retained index names are not compared.
`schema-reference-safety-test.php` forces a reference collision and a failure after CREATE:
foreign reference rows survive the collision, while owned partial DDL is cleaned up.

`test_schema_mariadb.py` is a separate opt-in `mariadb` test: Paymenter 1.5.9's fresh native
Domains install, uninstall/reinstall, upgrade, every retained table, data/metadata retention,
and reference cleanup after success and schema drift run on `mariadb:11.4.5`, with empty and
`p` connection prefixes (one character fits the existing 63-character balance index). Upstream's invoice migration contains literal unprefixed SQL, so the
prefixed fixture migrates native tables first, renames them, then runs the Domains lifecycle. Use `TMPDIR=/private/scratch PAYMENTER_TEST_APP=/pristine/paymenter
CONVERSION_MARIADB_PHP=/path/to/php-with-pdo_mysql python3 tests/test_schema_mariadb.py`.
It uses only an existing image and its own randomly named `dom-schema-*` container, with
`--network none`, a Unix socket, a temporary data filesystem and network-isolated PHP.
The receipt records runtime and confirms container removal and pristine dependency preservation.
The measured two-case run took 45 seconds, with a 512 MiB container and a 1.5 GiB
available-memory preflight. It is omitted from default runs; no provider calls, ZIP-uploader, checkout/worker race or live
acceptance is claimed. Its cost is one container startup and two fresh native lifecycle runs.
