# One-time source conversion

This development tool is excluded from the runtime archive. It supports the exact
source trees pinned by `source.json` on Paymenter 1.5.9. The default dedicated Linux/systemd
host policy and the explicitly selected app profile are described separately below.
No provider requests are made. Keep both registrar runtimes parked outside the active
extension directory during conversion. Unsupported source bindings, native plans, queue
shapes or host scheduling require a separately reviewed prerequisite; do not weaken guards.

Supply canonical `--app` and `--source` directories, `--source-revision` from `source.json`,
`--evidence` and `--units` JSON paths. Default is `--dry-run`. Use `--stage-schema` separately
if the neutral migrations have not run. Schema DDL is not transactional: verify all schemas
and migration records before proceeding; restore/review a partial foundation installation.
All native tables changed by cutover must be InnoDB on MySQL/MariaDB; SQLite fixture transactions are supported. Foreign journal runs or orphan maps refuse.
The converter's own journal DDL and row staging explicitly support interruption/resume.

For the default dedicated profile, `--units` has exactly these ordered keys: `checkout`, `schedulers`, `workers`, `dispatch`.
Each value is a nonempty list of the actual dedicated service/timer/socket/path names for that role.
Every listed unit must be masked and inactive. Include all user@ manager templates/instances and all PHP/web services plus activating sockets/paths and timer links. User systemd managers must be inactive and masked; any active user manager refuses. Maintenance mode must be active, cron absent,
and all application/web processes stopped. Cron/anacron/run-parts activation units must also be inactive and masked. All standard system and all-user cron spools
and periodic hourly/daily/weekly/monthly directories must be readable and empty; effective systemd definitions (including drop-ins, instances, aliases and trigger links) are inspected; inaccessible inventories refuse. Include every entry point in the host inventory;
shared PHP pools, external schedulers or inaccessible inventories are unsupported. Registrar
processing, billing and observations must already be off. Inspect these conditions again
between schema staging, dry run, backup, apply and restarting workers.

Evidence contains `accounts` indexed by native server ID and `domains` indexed by source
ledger ID. Each account requires `source=authenticated_provider_read`, canonical lowercase
`uid`, exact `environment`, `credential_digest` (SHA256 of JSON `[uid, token]`), `accepted_by`
(a staff ID with source view and policy permissions), a bounded snake-case `reason`, current
Unix `accepted_at` (24-hour window), and `domain_id` selecting its accepted ownership read.
Each domain entry contains its original `account_fingerprint` and offline authenticated
GetDomainInfo `xml` proving exact name, numeric DomainNameID, belongs-to and ISO date expiry.
Acceptance is the operator's independent provider evidence review, never inferred from a
label, credential hash, copied observation or an old success state. Keep evidence private.
The tool refuses ambiguous XML/date/account variants rather than guessing a contract.

Dry run reports exact source/plan digests, counts, unresolved operation IDs/states/actions,
pending IDs, pending payable invoices and unverified expiry triage within 60 days. Review
all pending/near-expiry names and unsupported terminal histories separately; they have no automated expiry notices. Apply
refuses any payable invoice linked to any retained holding, regardless of action or purchase proof.
Settle legacy invoices under the running legacy engine before this maintenance window, or
use native audited cancellation after customer communication and explicit authorization.
Unsupported terminal histories include ordinary completed/rejected nameserver or lock work:
expect demo/staging apply to refuse until a reviewed prerequisite slice supports them. Stop
and retain the legacy deployment; there is no operator bypass. For every pending name,
record a staff-managed renewal before apply or defer conversion until adoption is available;
the risk lasts until adoption ships, beyond the 60-day triage window. Repeat dry run only
after prerequisites are resolved. Every iteration requires a full maintenance window.

`--apply --backup-marker PATH` requires a prior private marker containing `revision`,
`source_digest`, `key_sha256` (SHA256 of the application encryption key string), plus
`database` and `files` objects with regular archive `path` and matching `sha256`.
Both nonempty archives must predate the marker. Back up the database and matching extension
files, retain the encryption key securely, and prove a restore before cutover; archive hash
validation alone is not a restore test. Preserve previous keys needed for encrypted history.

Do not rotate APP_KEY after conversion: current-key proof seals fail closed after rotation.
Retaining prior decryption keys alone does not preserve these authority seals.

Apply stages encrypted audit/ID-map rows first, then copies and verifies data in one DML
transaction before recording completion. A stopped stage can be resumed only with identical
source/plan/evidence within the evidence validity window below. Native server/product settings and rows being renamed are rechecked against the encrypted audit before copy. Leave maintenance active after any failure. Incomplete journals refuse
normal runtime boot. Completed repeat checks source/result digests and returns without writes;
post-cutover activity instead refuses replay. Source tables and failed jobs remain untouched. Source reminder claims map to neutral
suppression identities, preventing duplicate catch-up delivery. Customized templates retain
IDs/content/preferences; unsupported data/object/legacy-route references refuse. Compatibility
variables preserve absent invoice as null (including isset/null comparisons), USD currency and a false automatic-renewal promise. Conflicting saved
per-domain calendars/credit preferences need a reviewed prerequisite, not silent replacement.
For the dedicated profile, only the native default database `jobs` queue is supported; other backends/connections/tables refuse.
Known, unreserved legacy queue jobs are removed only with a durable ID map and encrypted
original payload audit; native jobs are retained. Legacy attempts remain held for review,
never fabricated as unsent, funded, neutral dispatch grants or new provider completions.
Neutral dispatch has no eligible converted historical attempts to enqueue. New obligations
can use positively sealed converted purchase anchors after controlled activation.

If Domains is installed during an incomplete journal, service registration blocks application
and artisan boot. Park Domains again before using the standalone converter to resume.
Install only Domains after completion, with new sales/processing/billing/observations off.
Verify IDs/counts/reservations, native money and null native expiries, retained encrypted
snapshots/EPP/contacts, notification IDs/preferences/custom content and permissions. Do not
install the legacy runtime alongside it. Review every unresolved attempt before enabling
new work. Keep maintenance active and units masked throughout verification. NameSilo has no imported account or acceptance; an independent native NameSilo server refuses conversion and needs a separate reviewed prerequisite. Adoption remains unavailable. Keep all feature gates
off through reopening. Make lifting maintenance and
unmasking units an explicit go/no-go only after verification and a rehearsed rollback.
That is the point of no return for simple restore: customers can create native writes.
Before reopening, rollback restores database/files/key, removes Domains, restores the parked
pinned legacy runtime into its active extension path, verifies its configuration, then
unmasks units and lifts maintenance. The parked-runtime file backup alone is insufficient.
After reopening or any new obligation, credit or provider write, stop new work and reconcile
an explicit repair/restore plan; never overwrite newer outcomes.

After reopening, enable read, billing and processing only as separate acceptance steps,
each with its own authority and verification. Read acceptance requires an authenticated
provider call and explicit task authorization; this offline converter grants none.

## Accepted source pins

`source.json` is a reviewed list of exact runtime byte inventories. Both the original
`b9b12e9` and `e1ace20` source revisions are accepted; use their full hashes from that file.
The selected revision binds the dry run, backup marker and sealed converted proof.
For a future clean local Enom checkout, first declare matching runtime proof revision
support, then run `php scripts/pin-conversion-source.php --checkout PATH --revision FULL_SHA`.
It never downloads and refuses changed/added/removed migration, model, settings or service/job bytes unless the
converter declares their exact schema digest in `SourceSchema::PIN_SCHEMAS`. A declaration
requires reviewed mapping/schema implementation, not an operator override. Review and test
the generated pin diff. Pin tooling is development-only and excluded from the runtime archive.

## App-scoped host profile

`--host-profile=dedicated` remains the default with the dedicated checks above.
Use `--host-profile=app --operator-inventory PATH` for a reviewed shared-host/container
maintenance window; do not supply `--units` with this profile. The private inventory is an
operator attestation, not automatic proof that remote entry points are disabled:

```json
{"profile":"app","stopped":["subscription scheduled task","application queue supervisor programs","external deployment and scheduler triggers"],"external_schedulers_disabled":true,"web_workers_drained_after_maintenance":true,"standard_public_index_only":true,"web_auto_prepend_disabled":true}
```

The app profile requires file-based Laravel maintenance with valid `storage/framework/down`
JSON with a nonempty prerendered `template`, missing/null `secret` and `redirect`, and
missing/empty `except`. Run `php artisan down --render=errors::503` without bypass options.
The native null fields and empty list are accepted unchanged; any configured bypass or
exception refuses. Verify the rendered template and 503 status. Drain workers only after the final maintenance file/fence is established. The native public entry
point and `storage/framework/maintenance.php` must match the reviewed Paymenter 1.5.9 and
Laravel fence bytes: this exits before Composer/application bootstrap, so earlier middleware
or container overrides cannot admit HTTP work. Modified/custom public entry points and extra PHP files in the public tree (including
storage links) refuse. Attest `standard_public_index_only=true` only after checking the
web-server document root, aliases, rewrites and PHP handlers execute only `public/index.php`;
attest `web_auto_prepend_disabled=true` only after checking the effective web PHP configuration
has no auto-prepend code. CLI inspection cannot prove those web-server settings.
Both runtimes must be parked and every registrar feature gate off. A cache-backed
maintenance implementation refuses. It inspects visible process commands and all inspectable
working directories; its own ancestry is exempt so the CLI can run. App-path commands/cwd
and all artisan processes from any path refuse, covering command abbreviations too. Foreign nginx,
Apache and PHP pools, own PHP-FPM pools with cwd `/`, and a supervisor with app programs
stopped can remain. After maintenance activates, drain/recycle the application PHP workers
and wait for all prior requests to finish; disabling new requests cannot unload old code.
Use a full pool/master stop or recycle that unloads prior OPcache workers; retain the old
and new master/worker PID or pool-status evidence. If own pool cwd is unreadable (common
with nondumpable PHP-FPM workers), stop that application pool for the entire window.
The process collector never bypasses an unreadable own cwd. If no inspectable own peer
remains on an unrestricted procfs, arrange a harmless long-lived process of the same uid
from `/` outside the application; do not falsify visibility or use root as a workaround.
Attest `web_workers_drained_after_maintenance=true` only after this is verified, and record
the actual pool/restart procedure in `stopped`. Own cmdline/cwd inspection failures refuse. Foreign cwd permission
restrictions are recorded; this cannot prove that a privileged foreign process cannot
write the application. Stop such entry points and account for them in the attestation.
An empty process inventory needs explicit procfs hidepid evidence; otherwise at least one
non-ancestral process of the effective uid must be seen.

The running user's `crontab -l` must work (`no crontab` is empty). Active app-path,
`artisan` or `schedule:run` lines in it or readable `/etc/crontab` and `/etc/cron.d/*`
refuse. Unreadable system cron files are recorded and require operator review. Other users'
spools are not read. This inspection cannot discover indirect shell wrappers or remote
triggers, user systemd timers, at jobs, periodic/anacron tasks or supervisor wrappers: inventory and disable them explicitly. A missing crontab executable refuses.

Only `database` (native default connection/table) and `sync` queues are supported; pending
or reserved Enom/Domains jobs block app conversion. Do not broadly clear jobs. Drain or
resolve registrar work under the legacy engine before maintenance; preserve unresolved
outcomes and restart the entire stopped-window procedure. Native jobs and failed-job audit
remain retained. The dedicated profile retains its reviewed known-job mapping/audit behavior.

Each full inspection takes a quiet snapshot of all tables of the application's database (including jobs,
failed_jobs and every planner input), max IDs/updated timestamps and row digests, waits
120 seconds by default, and compares a new snapshot. `--quiet-seconds N` permits a minimum
of 60 seconds; lower values refuse. Present Laravel schedule mutexes or any change refuse.
The scheduler's actual event/scheduling stores and the default cache are inspected, including
configured database lock connections. Split read/write PDOs or configured read replicas refuse
for the app database and every inspected cache/lock connection. Custom mutex names/implementations refuse; all live
database lock keys and standard schedule-value keys are inspected. Parked source schedules
use the reviewed standard names. A changed source scheduler needs separate support. File caches
must have no live entries, conservatively, because their hashed filenames cannot enumerate
all schedule keys. Unsupported/unreadable cache stores refuse; choose and verify a supported
persisted cache during a separately reviewed preparation window. No cache or mutex is
silently cleared. Plan at least 61 minutes after the scheduler's last tick before the first converter run:
the native heartbeat uses `onOneServer` every minute and leaves one-hour locks. Stop new
scheduler ticks without killing already running tasks or their process group; confirm every
`schedule:run` child has finished. A killed `withoutOverlapping` task leaves a lock until
its full configured expiry: native mail fetch defaults to 24 hours, and parked Enom
catalogue tasks can leave two-hour locks. Wait for every applicable lifetime and re-inspect.
Do not clear locks to make inspection pass. A file cache containing normal live framework
values also refuses; prepare a supported database cache in a separate window if needed.
Whole-table digests can be expensive on large installations and invalid JSON/UTF-8 values
fail closed; rehearse capacity before the cutover. Every converter re-inspection repeats the full policy and quiet window;
schema staging and apply can therefore take several windows. Retain the private host-check
receipts, including unreadable inventory disclosures. Snapshots are observations, not a
permanent exclusion lock: keep entry points disabled throughout backup/apply/readback.

Restore a pre-stage backup into an empty, recreated application database: importing over
staged tables is insufficient. Verify the original table/migration inventory, matching files
and retained keys, with no staged neutral or conversion-journal tables left behind. Record
prior legacy gate values in the reopening/rollback inventory. Restore them through the native
admin only for an authorized abort/legacy rollback; converted Domains gates remain off until
separate acceptance authorizes them. For legacy rollback, keep workers/schedulers paused
after restoration; obtain reopening approval, lift maintenance for native-admin access,
restore the recorded legacy gates, then resume the intended legacy programs and triggers.

## Docker demo procedure

Use only the target application's existing container. These are operator steps for a
separately authorized conversion; the development rehearsal never operates a deployed demo.

1. Resolve payable/history/pending-name prerequisites. Before booking the window, verify the
   CLI has `proc_open`, the app uid has a passwd entry, and native `crontab -l` works as that
   uid inside the image (a different BusyBox error shape can refuse). Record supervisor
   programs, root/user cron entries, external dispatchers and restart policies. Record and
   set the container restart policy to `no`; disable orchestrator redeploy/restart triggers.
   Inventory every sibling application container, image-based replica and other host using
   this application's database or storage. Stop or scale those other application consumers
   to zero for the whole window, disable their restart policies too, and list them in `stopped`.
   The in-container process check cannot see these writers. Keep database storage available
   for the CLI. If unreadable PHP-FPM is PID 1 and cannot stop while retaining CLI access,
   this exec-based procedure is unsupported; review a separate prerequisite rather than bypassing it.
   Stop new scheduler ticks and external dispatchers while leaving queue workers running.
   Disable root's app crontab or stop root dcron, including wrapper entry points. Let already
   running scheduler children finish; do not kill their process group. Inspect zero remaining
   registrar jobs, including reserved rows, under the legacy engine. In the native admin,
   record all prior Enom gate values and turn every registrar gate off before maintenance.
   Wait at least 61 minutes
   after the last scheduler tick and any longer overlap-lock lifetime described above.
2. As the app uid, run `php artisan down --render=errors::503` without secret or redirect.
   Verify the rendered template and 503. Drain/recycle the app PHP pool master after
   maintenance and verify no earlier request remains, then inspect registrar job rows again.
   If any remain, gracefully pause workers and abort: keep legacy installed, restore the
   original web/pool configuration, lift maintenance, restore recorded legacy gates through
   the native admin, then resume authorized legacy workers, drain and restart the procedure.
   Never delete jobs to pass inspection. Once zero is confirmed, gracefully stop queue/dispatch
   programs and prevent their restart. Verify every registrar gate is still off; park Enom
   and Domains outside `extensions/Servers`. Keep app-scoped/uninspectable pools and app-scoped
   nginx stopped. Verify the standard
   public-index/no-prepend web settings and preserve original configuration/source bytes.
3. From `/`, start a harmless long-lived `sleep` as the same app uid with no app path in its
   command. Keep it alive across staging, dry run, backup and apply to establish an inspectable
   non-ancestral own-uid peer; record it in the private inventory and stop it when reopening.
   Verify the full stopped-window prerequisites, including lock expiry. Only now accept
   account evidence: schema staging, dry runs, backup, apply and any incomplete-apply resume
   must all finish within 24 hours of the oldest `accepted_at`. If a stopped apply cannot
   resume in time, restore the pre-apply backup into an empty, recreated database; verify
   its exact table/migration inventory, retaining staged schema but no journal absent from
   the backup. Restart with fresh evidence; changing evidence in an incomplete journal
   is unsupported. Take a consistent
   pre-stage database dump plus matching files, parked source and encryption keys; prove an
   isolated restore. A partial schema-staging failure and rollback to legacy use this dump.
4. Place the standalone converter, accepted source, offline evidence and inventory outside
   active runtime discovery. Run as the app uid from `/` using
   `docker exec --user APP_UID --workdir / APP_CONTAINER ...`, with canonical container paths
   and `--host-profile=app`. Run `--stage-schema` separately when needed, then `--dry-run`.
   Retain disclosures and review exact digests/prerequisites. Any container or supervisord
   restart breaks the window: stop entry points again and repeat the entire procedure,
   including lock expiry. Do not continue using an earlier passing inspection.
5. Keep entry points stopped. For every `adoption_pending` and `pending_expiry_review` ID
   in the dry run, record staff-managed renewal coverage before apply or defer conversion.
   Take a second consistent pre-apply database/files/key backup,
   prove its isolated restore, and create its matching private backup marker. The archives
   must predate the marker and be readable by the app uid at canonical paths inside the container. Repeat the dry
   run and perform `--apply --backup-marker PATH` only after prerequisites pass. A refusal
   keeps the app stopped; there is no force flag. Before reopening, a repeat dry run with
   Domains still parked must report `complete` / `already_complete` with unchanged digests.
   Verify IDs/counts, native money/null expiry, reminders/preferences and unresolved attempts.
6. Install Domains alone with all gates off. Make the explicit reopening go/no-go before
   enabling any entry point, including workers that could bypass maintenance. The first
   re-enabled entry point crosses the rollback boundary, even before `artisan up`. Restore
   only the recorded intended programs, restart policy and triggers after acceptance, stop
   the harmless peer, and lift maintenance. Accept reads/billing/processing separately later.
   Before reopening, rollback restores the pre-stage database, matching files and key, removes
   Domains and restores pinned Enom to its original active path before enabling entry points.
   After any entry point/new activity resumes, stop and reconcile a specific repair plan
   rather than replaying an old dump.

## Plesk staging and production procedure

Rehearse the whole procedure and restore on staging before production. Use a private backup
and explicit maintenance window per subscription; do not stop other subscriptions' services.

1. Resolve the payable-invoice, unsupported-history and pending-name prerequisites above.
   Before booking, verify the subscription PHP CLI has `proc_open` and an inspectable native
   user crontab. Inventory Plesk Scheduled Tasks, Laravel scheduler/queue tooling, Git
   auto-deploy, supervisor/systemd programs, wrappers, deployment hooks and remote triggers.
   Disable new scheduler ticks and external dispatchers; keep legacy queue workers running.
   Let running schedule children finish without process-group kills, confirm no registrar
   rows remain (including reserved). In the native admin, record previous Enom gate values
   and disable every registrar gate before maintenance. Wait at least 61 minutes after the
   last tick plus every longer overlap-lock lifetime. Record precisely what the administrator must restore.
2. As the subscription user, run `php artisan down --render=errors::503` without a bypass,
   using the supported PHP CLI. Verify its rendered template/503 before stopping the pool.
   Establish static maintenance/deny handling before any PHP-handler change. Have the
   administrator drain and disable the subscription pool for the entire window, preventing
   on-demand workers from respawning; inspect old master/worker PID evidence and verify
   no earlier request remains. Verify the stopped-pool web server returns 503 and never
   serves PHP source as plain text. Then inspect registrar jobs again: if any remain,
   gracefully pause workers, keep legacy installed, restore original web/pool configuration,
   lift maintenance, restore recorded legacy gates in the native admin, resume authorized
   legacy workers and drain, then restart; never delete jobs. After zero is confirmed, stop
   workers, verify all registrar gates remain off and park both runtimes. Shared foreign pools
   and nginx/Apache may remain. Verify document root, handlers, aliases and no-prepend PHP
   settings before the two web-configuration attestations. Do not use root to bypass checks.
3. From `/`, start a harmless long-lived `sleep` as the subscription user, with no app path,
   and keep it alive across stage, dry run, backup and apply. Record it in the inventory;
   it supplies the inspectable own-uid peer after the pool stops. Verify the stopped-window
   and lock-expiry prerequisites. Only now accept account evidence; schema staging, dry
   runs, backup, apply and any incomplete-apply resume must finish within 24 hours of the
   oldest `accepted_at`. If a stopped apply cannot resume in time, restore the pre-apply
   backup into an empty, recreated database and verify its exact table/migration inventory,
   retaining staged schema but no journal absent from that backup. Restart with fresh
   evidence; changing evidence in an incomplete journal is unsupported. Take a consistent pre-stage database/files/parked-source/
   encryption-key backup and prove isolated restore. Partial staging failure and rollback
   to legacy use this pre-stage backup. The administrator must account for unreadable system
   cron files, privileged writers, timers and indirect/remote entry points in the attestation.
4. Run the standalone converter from `/` using `sudo -u SUBSCRIPTION_USER -- ...` and the
   subscription's supported PHP CLI, canonical paths, private inventory and `--host-profile=app`.
   Run schema staging separately, then dry run and review the exact result/disclosures.
   A pool/service/deployment restart invalidates the window: stop again and repeat the full
   procedure and lock wait. Hidden/unreadable privileged sudo ancestry refuses; resolve this
   readiness prerequisite separately without weakening the profile.
5. Before apply, require recorded staff-managed renewal coverage for every dry-run
   `adoption_pending` and `pending_expiry_review` ID, or defer conversion. Still stopped,
   take a second consistent pre-apply database/files/key backup, prove restore
   and create the private marker. Its archives must predate it and be readable at canonical
   subscription paths by that user. Perform explicit apply as the subscription user. A failed
   or unknown result keeps maintenance active and workers disabled. Repeat dry run with
   Domains still parked: it must report `complete` / `already_complete` and unchanged digests.
   Retain counts/IDs, finance, reminder, pending-name and encrypted-evidence readback receipts.
6. Install Domains alone with gates off. The administrator and operator make reopening
   go/no-go before any entry point resumes; the first restart crosses the rollback boundary,
   even with maintenance active. Restore only recorded intended tasks/services, stop the
   harmless peer, then lift maintenance. Separate authorized read/billing/processing
   acceptance follows. Before reopening, rollback restores the pre-stage database/files/key
   and pinned Enom active path before entry points; after reopening, reconcile a repair plan.

Practical readiness limits: Redis queue/cache configurations are unsupported. Review all
queued work before maintenance; registrar-name matching is deliberately conservative and
can reject native messages too. Never discard jobs merely to obtain a pass. Any visible
`artisan` process refuses, including another subscription's. Hidepid plus an unreadable
privileged ancestor refuses. Live locks under earlier prefixes or other users of a shared
lock table also refuse. Resolve prerequisites separately; do not weaken inspection.
