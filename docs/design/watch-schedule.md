# Public watch scheduling (dormant)

Public registry expiry is independent of registrar transfer prechecks and of DNS facts.
`PublicRegistryReader` accepts the existing DNS reader, a public bootstrap snapshot,
transport and a shared job context. HTTPS endpoints come only from validated IANA data;
verified public addresses are pinned while the original TLS hostname is retained.
At most three HTTP requests and ten DNS queries share the 45-second deadline.
Only the strict selected-domain 404 classifier returns `not_registered`; this never grants
registration, transfer, billing, reservation release or notification authority.
Missing/conflicting/redacted expiry is unknown. No WHOIS or referral fallback is used.

Migration 000031 retains encrypted registry observations, an independent HTTP quota family,
scheduler/discovery cursors and public evidence reference pins. Installation verifies complete
schema and migration history; down/uninstall retain it. No work or settings are seeded.
Existing watch work stores expiry claims alongside its independent delegation/points claims.
Capture/commit rechecks native owner, property, map, watch, source, profile and PSL identity.
One exact claim has a 120-second lease; results at or after 45 seconds are discarded.
An abandoned claim enters per-kind backoff before another read, yielding its owner turn.

RDAP reserves at most 30 requests/minute globally, six per host and one in flight per host.
Endpoint resolution spends the existing shared 60-query/two-active-job DNS pool.
RDAP circuits/backoff never borrow registrar quotas or replace DNS observations.
Five endpoint failures open an hourly probe circuit; 429 Retry-After is capped at 24 hours.
Transient per-kind retries use 15m/1h/6h/24h plus deterministic jitter up to ten percent.
Local saturation defers 60 seconds without failure or invalidating still-fresh evidence.

Expiry runs daily plus 0–60 minutes deterministic jitter, every six hours within 30 days of
expiry or afterward. No coverage waits seven days or a changed bootstrap snapshot; validated
bootstrap data is cached seven days and retained on failed refresh so IANA outages do not
block a healthy registry. No published expiry remains daily, without invented facts.
Expiry is stale at 48 hours, reduced to 12 hours whenever the current date is within 30 days.
NS checks use six hours (one hour for confirmed expected-platform DNS-only names), confirming
matching/mismatching classifications twice under the same configuration after the prior TTL
or 15 minutes, capped at six hours. Apex-only delegation keeps its source cohort fixed.
Confirmed changes coalesce an early independent points check. Points remain daily/48h fresh.
Stale, unknown and unconfirmed evidence cannot create notices.

The sole extension tick dispatches five-minute turns only after an accepted local discovery
producer is explicitly bound and an asynchronous queue has retry_after >=180 seconds.
Discovery visits at most 100 service IDs in 25-ID pages and rotates durable product turns.
Native saved/deleted events enqueue local after-commit hints, retaining hints during a scan;
failed hints are logged and cannot fail a committed native save;
retained source IDs allow the producer to retire deleted or moved services. Disabled mappings
visit retained IDs only, allowing cleanup without enrolling new native services. No new cron runs.
Watch and managed DNS jobs share the existing public owner cursor: ten jobs, two per owner.
Each job has one attempt, a 60-second timeout and a 120-second uniqueness window.

A read-only status on the existing staff DNS resolver page reports disabled, invalid queue,
a first turn waiting state, two missed dispatch turns, or a family with pending work and
no successful progress for 24 hours. Each proven successful daily job gets its expected completion and two dispatch turns
before that family can alert, including registry expiry’s 0–60 minute jitter. Failed or abandoned
claims cannot extend this grace. Managed health shares dispatch eligibility. Family-specific
advice separates registry and DNS failures; a new failing family starts a new alert episode. Alerts are deduplicated, with optional native staff inbox delivery when
that inbox is enabled. Failed local discovery/enrollment cannot block independent dispatch.
Retention prunes at most 100 old observations per family per turn after 90 days, preserving
last successful per-kind/axis facts and pinned notice evidence under observation-row locks.
Activation, the accepted discovery binding and installed queue/resolver acceptance remain
separate gates; shipped composition is dormant and creates no work on fresh installation.

The explicit two-jobs-per-owner/five-minute-turn ceiling permits at most 576 jobs/day per
owner and 2,880 globally. Rough baseline capacity is 96 watched names/owner or 480 globally
before managed checks, urgent expiry or retries. With more than five backlogged owners,
each owner gets about 576 times five divided by the owner count per day. Capacity acceptance must size the rollout
within these policy bounds; wrapping one owner within a tick would violate the owner cap.
