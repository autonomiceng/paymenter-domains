# Hosting domain discovery

`WatchDiscovery::discover(serviceId, productId)` supplies the local producer for the existing
`WatchDiscoveryWork` scheduler. It reads native hosting services and one saved primary property;
it never calls a hosting panel, resolver, registrar, or notification channel.

Installation remains dormant. After queue, resolver and public-data acceptance, deployment
composition supplies `new WatchDiscoveryWork(fn($id, $product) =>
app(WatchDiscovery::class)->discover($id, $product))` and enables `WatchWork` with that same
worker. No new setting, environment variable, native schema or scheduler is introduced.
The existing five-minute discovery turns visit at most 100 IDs in 25-ID pages, retain event
hints and revisit completed products daily. `WatchWork` infers unconfigured primary fields;
an existing staff Off choice is never inferred back On.

Only one exact scalar hostname is accepted. Case and one trailing root dot are normalized;
URLs, ports, paths, IPs, lists, controls, Unicode and A-label IDNs are held. The current validated
ICANN suffix rules determine the registry name, including wildcard exceptions. PRIVATE boundaries
and unknown suffixes are held. Missing, damaged, future-dated or over-90-day suffix data prevents
new derivations; unchanged existing identities retain their ordinary checks and lifecycle cleanup.
Held sources contain safe reason codes, never invalid property values.

One customer/name watch can have several hosting sources. Staff pause survives discovery and
removal/return. Suspension retains sources and pauses a watch only when no source is active;
active siblings still run checks. Cancellation, deletion, ownership mismatch, mapping Off or a
changed property archives the old source. Last-source removal ends checks and suppresses only
definitely unhanded notices. Return re-adds the watch with fresh source identity and revision;
old observations, channel handoffs, preferences and audit are retained without replay.
End-of-period cancellation keeps checking until the native service actually stops being active.

Managed links require the same ledger and native service customer. Foreign reservations are
indistinguishable from no match. Pending acquisitions link read-only without asserting platform
registration; adoption-pending holdings remain external. Active holdings stop duplicate external
checks and retain a linked tombstone. Existing managed enrollment independently proves holding
authority before creating public DNS work. Discovery never creates funding, reservation, renewal
or registrar authority. New watches inherit the site DNS default; legacy product-profile IDs are
unused, as in the current DNS setup contract. Explicit watch choices are preserved.

Native ownership, order, product, mapping, saved values and suffix revision are locked/rechecked
in the local transaction. A changed source is archived before its unique service key is reused.
After-commit hints enroll ordinary work; lost hints are repaired by the bounded daily sweeps.
The regression evidence is offline native SQLite, not acceptance of MariaDB concurrency or live
queue/resolver behavior.
