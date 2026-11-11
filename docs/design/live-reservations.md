# Live reservations

`LiveReservations` owns durable UTC call and USD spend reservations. Its
`LiveReservationPolicy` supplies local deployment, site and account authority plus
the effective daily limits. A policy must refuse an unadmitted binding and must
not make provider requests. The service independently requires the current stored
Live binding before it reads or updates counters.

Lineage uses the canonical, immutable `Account::stable_identity`, never a token,
credential revision, server ID or binding fingerprint. Enom retains its deployed
identity hash; other providers use a hash of provider, Live environment and local
identity. Persisted usage follows that identity when a binding is enrolled in a
restored deployment. Key rotation cannot create fresh capacity. Existing account
binding immutability remains enforced; provider policies must keep enrollment
attached to the same local account.

The shared schema retains the historical `domains_enom_live_calls` name and
migration. Its rows are now shared infrastructure with isolated provider keys;
Enom holding/billing tables remain Enom-owned. No upgrade copies, drops or resets
existing counters. `EnomLiveCalls` and `EnomLiveCallSchema` retain their transport,
reader and installation entry points. Enom's policy reads `EnomLive::account`,
including any effective call-limit override supplied there.

One conditional SQL update reserves a call and optional exact cost together.
Failed or unsent attempts retain reservations. A UTC day change after reservation
refuses the send. Nested background scopes preserve half of the account's call
allowance for checkout and restore the scope on exceptions. Unreadable storage,
invalid limits, stale bindings and non-USD costs refuse without transport details.

Live catalogue scopes count price and TLD-fact reads separately in `catalogue_used`.
The atomic update requires both `used < daily cap` and `catalogue_used < floor(30% of cap)`;
ordinary customer work consumes only `used`. `CatalogueShare` supplies the same share arithmetic
and purpose marker used by Enom TEST. The additive migration defaults old rows to zero catalogue
calls, retaining all total calls and spend. Reinstall preserves both counters and refuses missing
recorded or unrecorded counter storage. Existing minute leases and non-catalogue headroom stay in force.

Reservation is not a dispatch grant, credential check, funding proof or provider
price cap. The caller must keep its one-send and UTC-day guard at the physical
boundary. `NameSiloLiveReservationPolicy` checks the current local binding, enabled
server, matching HTTPS site, USD and balance funding. Admitted transport and
actual-charge reconciliation remain separate responsibilities; reservation alone
grants no NameSilo Live send authority.

`live-reservation-lineage-test.php` rotates Enom and NameSilo credentials through
the binding service, then reconstructs the ledger and checks the remaining call
and spend allowance. It also replaces the same key after exhaustion. These
synthetic checks establish local continuity, not provider acceptance or concurrent
MariaDB behavior.
