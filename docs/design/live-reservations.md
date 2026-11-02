# Live reservations

`LiveReservations` owns durable UTC call and USD spend reservations. Its
`LiveReservationPolicy` supplies local deployment, site and account authority plus
the effective daily limits. A policy must refuse an unadmitted binding and must
not make provider requests. The service independently requires the current stored
Live binding before it reads or updates counters.

Lineage uses the canonical, immutable `Account::stable_identity`, never a token,
credential revision, server ID or binding fingerprint. Enom retains its deployed
identity hash; other providers use a hash of provider, Live environment and local
identity. A new server binding with the same local identity shares usage. A key
rotation cannot create fresh capacity. Provider policies remain responsible for
keeping enrollment attached to that same local account.

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

Reservation is not a dispatch grant, credential check, funding proof or provider
price cap. The caller must keep its one-send and UTC-day guard at the physical
boundary. NameSilo policy, admitted transport and actual-charge reconciliation are
separate work; this seam alone enables no NameSilo Live requests.
