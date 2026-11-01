# Managed public DNS (dormant)

Migration 000030 extends the existing 000018 enrollment ledger; it never replaces it.
The retained key remains domain/generation/kind and the column remains `generation`.
Additional work stamps, leases, backoff and cursors start unset or zero; preexisting
rows retain IDs, account/profile/origin pointers, due dates and `awaiting_acceptance`.
No migration accepts work or copies watch facts. Down/uninstall retain all history.

Separate configuration stores revisioned default-profile selection, expected DNS and
independent public-notice preferences. Encrypted append-only managed observations
use the same normalized facts as watch DNS, with holding/generation/config stamps.
Public target descriptors identify either a watch or a holding; they grant no authority.
The public pool stores independent DNS admission and a repair cursor, never registrar
account quotas. Installation verifies all retained tables and upgrade history even
when migrations skip, and refuses partial upgrades rather than filling lost history.

This storage boundary adds no reader, dispatch, job, route, staff copy or activation.

Configuration keeps predecessor episode pointers separately from fresh evidence and has editor/reason fields for future configuration actions. Its owner is immutable; a mismatched owner must pause work rather than reparent facts.

The configuration's `dns_episode` is the continuity source for future notices; original A3 work pointers remain audit references. Neither is fresh DNS evidence. Missing profiles can store only unknown managed facts. New active/paused watches refuse same-owner managed or acquisition-progress reservations; foreign and adoption-pending watches remain allowed.

Only current-generation work is claimable; runtime reconciliation retires predecessors atomically. The pool row deliberately coordinates short quota and cursor updates without network or broad native locks. `cursor` rotates query hosts, while `source_cursor` rotates bounded native source cohorts. Interrupted nontransactional DDL refuses automatic repair: restore the pre-upgrade database backup and rerun installation, or have staff reconcile exact DDL and migration history before verification.

Managed observations use `generation` consistently with the retained work key (the spec calls it `authority_generation`). `ns_confirmed` means repeated public delegation samples only; it never confirms registrar authority.

Explicit composition accepts existing dormant work in place through `enqueue` or
`ManagedPublicReconciliation::sweep`. The sweep visits at most 25 holdings per tick
(100 maximum), retains its cursor, and schedules a new pass one day after completion.
Current generations only produce DNS hints: at most ten hints, two per owner, with
a persistent owner turn. No public expiry kind, native cron hook or activation ships.

Relink locks holding identity before configuration, profiles, sources and work. Each
transaction archives and links at most 100 predecessor sources, stops watch claims
and definitely unsent notices, and ensures managed work. Further source batches are
durable repair intent; omitted hosts keep points partial. Hints run after commit only
when durable enrollment changes. Repeated observe calls do not recursively emit hints.
Predecessor pointers preserve episode continuity, never watch freshness or NS proof.

Readers capture native/ledger ownership, immutable anchor, binding, generation,
revision, profile, source and configuration stamps; network runs outside transactions.
Commit rejects stale stamps, replaced claims, clock reversal and the 45-second limit.
Invalid hosting sources remain excluded from host queries, keep points partial and
do not disable the independent apex. Source evidence still stamps mid-read changes.
Managed NS keeps a stable source cohort; points rotates independently when necessary.
Managed points always visits the apex first and advances an overflowing source cohort
after its bounded attempt, even when long chains leave that hosting read incomplete.

Both watch and managed observers reserve each query in the same persistent public
pool: 60 queries per minute and two active jobs, independent of registrar gates and
budgets. Local saturation defers 60 seconds without increasing failure counts. Each
job retains the ten-query bound and existing classifier. Missing profiles
produce unknown. Repeated NS samples confirm after 15 minutes or prior TTL, capped
at six hours. An unconfirmed candidate can wait its TTL beyond badge freshness; an
expired confirmed sample never carries forward. Only a new confirmation coalesces
an early points check. Transient enrollment errors retain valid work for retry;
confirmed invalid authority retires it. Failed authority captures use the normal
per-kind retry backoff so one broken holding cannot consume its owner’s entire turn.

Managed projections select owner/account/generation-bound managed facts, retaining
old values and timestamps visibly stale after failure, target changes or retirement.
DNS observations never update holding expiry, financial state or managed permissions.
Enabled reconciliation retires expired, missing and moved-out holdings. Default-off
composition leaves original A3 `awaiting_acceptance` rows and its audit marker alone.
