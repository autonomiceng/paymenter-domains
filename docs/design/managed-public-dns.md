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
