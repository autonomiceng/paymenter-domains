# One-time source conversion

This development tool is excluded from the runtime archive. It supports only the exact
source tree pinned by `source.json`, Paymenter 1.5.9, and a dedicated Linux/systemd host.
No provider requests are made. Keep both registrar runtimes parked outside the active
extension directory during conversion. Unsupported source bindings, native plans, queue
shapes or host scheduling require a separately reviewed prerequisite; do not weaken guards.

Supply canonical `--app` and `--source` directories, `--source-revision` from `source.json`,
`--evidence` and `--units` JSON paths. Default is `--dry-run`. Use `--stage-schema` separately
if the neutral migrations have not run. Schema DDL is not transactional: verify all schemas
and migration records before proceeding; restore/review a partial foundation installation.
All native tables changed by cutover must be InnoDB on MySQL/MariaDB; SQLite fixture transactions are supported. Foreign journal runs or orphan maps refuse.
The converter's own journal DDL and row staging explicitly support interruption/resume.

`--units` has exactly these ordered keys: `checkout`, `schedulers`, `workers`, `dispatch`.
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
source/plan/evidence. Native server/product settings and rows being renamed are rechecked against the encrypted audit before copy. Leave maintenance active after any failure. Incomplete journals refuse
normal runtime boot. Completed repeat checks source/result digests and returns without writes;
post-cutover activity instead refuses replay. Source tables and failed jobs remain untouched. Source reminder claims map to neutral
suppression identities, preventing duplicate catch-up delivery. Customized templates retain
IDs/content/preferences; unsupported data/object/legacy-route references refuse. Compatibility
variables preserve absent invoice as null (including isset/null comparisons), USD currency and a false automatic-renewal promise. Conflicting saved
per-domain calendars/credit preferences need a reviewed prerequisite, not silent replacement.
Only the native default database `jobs` queue is supported; other backends/connections/tables refuse.
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
