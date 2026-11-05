# NameSilo DS policy and sequence handoff

Backend D builds on signed C0 `e1631bd4`; reuses NS14 R2 `5494e391`'s parser, duplicate-envelope guard,
longest-suffix refusal and exact tuple mappings. Zero SQL migrations or old-row rewrites. No provider calls.

## Deployment contract

`DsRegistryPolicy::DEPLOYMENT_FACTS` is **[]**. No real suffix qualifies from saved evidence; .com/.net/.org
remain Off. API examples, IANA numbers and registry signing keys do not establish child-DS acceptance.
Only deployment review may change this constant; there is no customer/staff allowlist setting.

Facts use C0's envelope `['format'=>1,'entries'=>[suffix=>fact]]`. Each complete fact includes matching `suffix`,
`input=ds|key`, integer algorithm/digest sets, explicit `combinations` of algorithm/digest_type pairs,
`source`, `version`, ISO `reviewed_at`/`valid_until`, and `expiry_policy=refuse`.
Policy requires an explicit clock, `reviewed_at <= now < valid_until`, DS input and an allowed pair.
Unknown versions, key-only, malformed or expired facts grant nothing. Select the longest **raw** suffix before
filtering validity; invalid longer facts cannot fall back to a broader entry. A suffix itself is never a domain.

`DsRegistryPolicy::snapshot(name)` and the port's C0 `dsMutationPolicy(name)` return the full validated fact
plus `format=1`, or null. Null grants no NameSilo mutation. Enom's existing behavior has no new policy veto.
The DS-only adapter extends `Management`; it does not activate the separate dormant DNS editor.

## Exact tuple protocol

Saved NameSilo list/add/delete docs define `dnsSecListRecords`, `dnsSecAddRecord`, `dnsSecDeleteRecord`.
List sends `domain`; add/delete send `domain`, `digest`, `keyTag`, `digestType`, `alg`. No invented record ID.
Descriptors remain outside `RegistrarRequest`; diagnostics/serialization omit domain/tuple and restoration refuses.

`Ds::inventory` preserves complete/partial/unknown. Only an explicit JSON list, including `[]`, can be complete.
Missing/null/object collections, duplicate JSON keys or unknown envelopes cannot establish empty state.
Malformed rows and extra collection metadata withhold completeness; valid partial siblings may survive.
All tuple fields participate; digests normalize uppercase. Recognized wire lengths: digest 1=40, 2=64, 4=96 hex.
Those syntax checks grant no registry support. Bounds: 50 rows, 1 MiB, JSON depth 24.

## Dormant management port

`new DsManagement(binding, exchange, facts=[], clock)` consumes C0's existing sequence interface.
`validate('ds_add'|'ds_delete', ['ds'=>tuple, 'confirm_last_delete'=>bool?], name)` refuses unsupported policy
before reads. `plan` independently reads owned Active identity and complete DS inventory, rejects duplicate adds
or absent deletes, and emits exactly one same-action child step. Last-record deletion requires the confirmation
flag. The complete policy freezes at `policy.management.sequence.preimage.ds_policy`, alongside the exact records.
No current deployment or observation row is backfilled.

`prepare` rechecks frozen-vs-current policy and independent unchanged inventory. The inherited engine's one-use
grant, identity/generation/configuration stamp, reservations, read budget and deadline remain authoritative.
`apply` can send only the exact named tuple through that grant. Acknowledgement alone cannot complete it.
`observe` reads owned identity and inventory independently, proving the exact addition/deletion with all siblings
preserved. Final deletion needs explicit complete `[]`. It returns `confirmed` and encrypted server-only
`facts.sequence_preimage`; malformed or partial reads remain unconfirmed. Already sent requests may reconcile
from their frozen rule after expiry/revocation; a changed/expired current rule cannot grant another send.

## C1 delivery

Apply [the bounded patch](demo-c-ds-c1.patch) serially after reviewing shared owners' changes. It only proposes
physical command classification, exact camel-case DS parameter exceptions and TestRuntime composition.
No shared source, factories, manifest, schema, migration, install or verifier file is changed by D.
For externally supplied Live factories, construct the same account-bound port with the deployment constant and
accepted clock, then retain the existing `useReservation(transport.reservation)` and composition checks.
Keep deployment map empty. DS read discovery belongs to O's separately proved ObservationReader;
mutation Off must not make O's read expectations fail. This adapter does not widen read expectations.

C1 must test the real admitted transport with synthetic cURL: classify list as catalogue and mutations as management;
reject extra/case-alias/credential keys and malformed tuples, and retain per-account budgets/no-replay grants.
The saved mapping's `keyTag`/`digestType` need the patch: the current transport's lowercase-only guard rejects them.
Parent owns MariaDB/fresh/upgrade/reinstall/prefix acceptance and resolution of inherited fixture failures.

DS fragments execute through existing registered `enom-protocol-test.php` and `management-namesilo-test.php`.
There is no manifest edit. C1 may give them dedicated entries later; preserve the paired Enom protocol, empty/unknown
DS parser and payload checks. Native sequences require real encrypted operation casts and engine grants.

## UI lane contract

- Current customer: DS Off, plain support wording. Staff: missing reviewed registry facts and evidence next step.
- Eligible editor: full `dsMutationPolicy` gives allowed pairs/sets and suffix; enforce the pair, not their cross product.
- Final delete: exact full tuple plus `confirm_last_delete=true`; a generic success acknowledgement is insufficient.
- Before send: complete inventory required. After send: existing queued/uncertain/read-only checking states.
  `confirmed=false` is never empty; use inventory state. Confirmed final delete requires complete empty inventory.
- Keep source/version/review reasons staff-only. The mock tuples are synthetic examples, never deployment facts.

These are backend contracts for the approved NS14 sheets; no UI or provider/installed acceptance is claimed here.
