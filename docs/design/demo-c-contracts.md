# Demo C shared contracts (C0)

Base: `3959a5583d6d0cb0d34a513f11084a5b2b197892`. Readers and dormant seams only;
no SQL migration, producers, backfill, account activation or transport changes.
Old annual renewal/transfer acceptance and existing registration terms stay unchanged.

## Frozen interfaces

- `Contracts/ObservationReader`: `bindsTo(AccountBinding): bool`,
  `observationCapabilities(): array`, `discover(ManagementRequest, RequestContext, array $knownGlue=[]): array`.
  The capability shape is `['revision'=>string|null, 'families'=>['dns'|'srv'|'ds'|'glue'=>'supported'|'unsupported']]`.
  Read support is independent of mutation eligibility. Unknown state is never complete empty.
- `ManagementProtocol` implements that read port. Its default expectations are unsupported;
  `dsMutationPolicy(string $name): ?array` defaults to null. This is an optional future
  new-request metadata seam, not an additional veto on existing Enom DS behavior.
  A non-null policy freezes the complete validated DsFacts suffix entry plus `format=1`: suffix,
  input, source/version, review/validity instants, algorithm/digest sets, explicit combinations and expiry policy.
  D supplies the reviewed policy; C1 consumes it only for new NameSilo DS requests.
- Current Enom discovery uses revision `enom-records-1`, with all four record families readable.
  Current NameSilo uses `namesilo-records-1`, with only glue readable. Neither emits new metadata.
  O's separate reader may extend the NameSilo read expectations after its bounded discovery proofs.
- NameSilo `Management` accepts an optional trailing deployment `$dsFacts=[]` map and exposes
  `dsRegistryFacts(DateTimeImmutable): array`. Reading it grants no DS mutation capability.
  `DnsManagement` is extensible for D's dormant `DsManagement`; it remains unselected by factories.
- `RuntimeComposition::make` accepts a trailing optional `ObservationReader $observationReader`.
  Absent injection preserves the default seven-key factory shape; the new key is opt-in.
  `RuntimePorts::observationReader(binding)`
  resolves the account-bound read port; old factories without that key retain their management port.
  Foreign/invalid injected readers refuse composition. C1 alone wires consumers and accepted factories.

## Optional payload readers

`Data/ReviewedFacts` accepts envelopes `['format'=>1,'entries'=>array]`. Missing, malformed or
unknown formats return no entries. Each proof has nonempty `source`, `version`, strict ISO
`reviewed_at`/`valid_until` instants and is current only for `reviewed_at <= now < valid_until`.
These readers check shape and validity; T/D still approve named source/rule versions and identity.

`Catalogue/ExpansionFacts::{term,family}` reads decoded `catalogue_facts`.
`OfferState::{termEvidence,familyEvidence}` reads the same optional siblings from stored JSON,
returning null on absent/invalid evidence without altering the ordinary facts codec or evaluator:

- `term_evidence`: envelope entries keyed by register/renew, then integer year 1–10.
  A proof contains `terms` (distinct integer years), `price_unit` (`term_total` or `annual`),
  and a nonempty `rule`. The requested year must be explicitly included. Transfer has no expansion.
- `renewal_families`: envelope entries keyed by ordinary/grace. In addition to the above,
  require nonempty `states`, `expiry_precision` (date/instant), integer `horizon_years` (1–10)
  and `safe_boundary`. Grace additionally requires `restoration_disabled=true`.
  Price/rule references alone do not supply exact prices or renewal authorization.

`Services/RecordFormat::read` is called by `ObservationRecords::current` after the unchanged
identity check. All three metadata keys must be absent (legacy) or present together:
`format=1`, `capability_revision=<registrar>-records-1`, `expected_families` containing all four
families with supported/unsupported expectations. Unknown revisions/formats refuse the snapshot.
Unsupported or malformed families become unknown individually. Valid partial siblings/opaque
provider rows survive verbatim; no Enom canonicalizer is applied. Legacy NameSilo rows cannot
certify DNS/SRV/DS. Legacy Enom inventories retain existing authority. Reads never rewrite ciphertext.
O must continue enforcing timestamps, sequence watermarks and provider canonicalization at use time.

`NameSilo/DsFacts::read(mixed $map=[], ?DateTimeImmutable $now=null): array` defaults to empty.
Envelope entries are keyed by canonical suffix and require matching `suffix`, `input` (ds/key),
integer `algorithms`/`digest_types`, nonempty explicit `combinations` of algorithm/digest_type pairs,
the shared review fields and `expiry_policy=refuse`. Unknown/malformed/expired entries grant nothing;
valid siblings survive. Key input is readable evidence, not permission to implement unsupported key writes.
D owns longest-suffix selection, tuple rules, policy version freezing and exact independent readbacks.

## Exclusive ownership after C0

| Lane | Owns and consumes | Must not edit |
| --- | --- | --- |
| T / NS17 | `Catalogue/{Facts,OfferState,ExpansionFacts,Capabilities,Evaluator}`, provider Driver/OfferFeed/TestCatalogueFacts; named renewal/billing services; term tests | Shared protocol/runtime, schema/manifests, D/O/X services, staff/UI |
| D / NS14 | NameSilo `DsFacts`, DS helpers/policy/config, Api/DsMapping, new DsManagement; DS tests | ManagementProtocol, Management/DnsManagement, runtime factories, schema/manifests, O observation services |
| O / NS15 | RecordFormat, ObservationRecords/projections/record services, provider ObservationReader helpers and observation/CAA tests | ManagementProtocol, provider Management/DnsManagement, runtime factories, D DS helpers, schema/manifests |
| X / B9 | LiveActivation/EnomLiveActivation, Enom ActivationEvidence, LiveActivationAction, transfer tests | Shared protocol/runtime, catalogue/DS/observation services, AccountChecklist and other UI |
| C1 | Shared protocol, provider Management/DnsManagement, RuntimePorts/RuntimeComposition/factories, manifests, all schema/install/verifier files | Apply bounded D/O wiring patches serially; do not independently change lane algorithms |

`Data/ReviewedFacts` stays with C1. Test registrations are serialized by C1.
UI needs separate approval/ownership; WhatYouSell/SellTable remain with the fix lane.
Packaging `scripts/build.py`, `tests/test_package.py`, `tests/enom-money-test.php` remain with CI.
No lane adds SQL migrations. Parent owns MariaDB, rebase onto the fixed B top and final acceptance.
