# Staff settings reads and one-use reviews

TLD list cells share one server-only facts snapshot per TLD during the table render.
Fresh staff grants are resolved once for that render; neither grants nor snapshots are
Livewire properties or write authority. Price validation reuses the same pure evidence
validator as fresh catalogue reads. Writes still reload and lock current evidence.

`domains_staff_review_keys` retains the consumed random 128-bit ticket key under the
composite primary key `(server_id, review_key)`, with ticket expiry for future retention policy. The account/server mutex serializes
claims; the unique constraint is a second duplicate guard. A local failure rolls back
the claim and write together. I/O request phases commit consumption before leaving
the transaction; only the same verified review instance can perform its in-flight
recheck. Tickets still bind actor, account, TLD, action, expiry and current state.
Reinstallation retains consumed keys. This unreleased foundation declaration evolves
in place; there is no upgrade migration or reconstruction from encrypted archives.

The current encrypted native setting remains bounded by 64,000 bytes. Rotation moves
older batches into extension-owned `domains_audit_archives`, with encrypted payloads
and an index on `(server_id, family, id)`. Reminder schedule archives use the same
store under a separate family. Native extension calls load all server settings, so
archives must live outside that relation. Writes read only their current batch; audit
retention is independent of replay authority.
Stamps and non-audit settings locks exclude the current audit and legacy archive keys
in SQL. No archive conversion or silent repair of older development schemas runs.
Recent history reads the current batch and latest archive only, then displays at most
50 entries. TLD filtering applies within that recent account window, so it may show
fewer entries; older batches remain retained. Corrupt current history refuses writes;
corrupt older archives cannot block a safe change or force a full-history scan.

Narrowing terms or pausing existing sale flags requires no catalogue acceptance proof.
Widening bounds and restoring withdrawn sales still require explicit confirmation and
current acceptance/import evidence. Disabling renewal support remains forbidden while
retained holders depend on it.
