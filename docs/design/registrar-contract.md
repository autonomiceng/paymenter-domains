# Internal registrar foundation

PR03 defines immutable typed values and a synthetic seam, not a working financial engine.
`RegistrarDriver` exposes named read/reconcile methods with an explicit bound context.
`AdmittedRegistrarDriver` is a separate writer interface, never a reader subtype. It declares register/transfer/renew methods using `AdmittedOperation`;
no generic execute/command/parameter map exists. No real driver implements either interface yet.
The existing NameSilo API client retains its own read-only types and has no neutral write bridge.

## Identity, requests and capabilities

`AccountBinding` fingerprints registrar/server/environment/stable account identity. It stores no
credential or raw account identity. Construction validates shape, not provider ownership; registry
binding/positive account acceptance arrives in PR06. Credential rotation must retain stable identity.
A native server cannot be repurposed once referenced. `RequestContext` fixes binding, origin and
deadline, with a shared mutable `RequestBudget` token counter and an engine-selected clock. Drivers must claim a token before
**each** wire request; binding/deadline refusals consume none. No endpoint/credential lives here.

Query terms are explicit and do not establish eligibility. Input must already be canonical ASCII;
storefront normalization/reservation policy follows in PR05. Supported action terms are explicit;
missing capabilities stay unknown. Privacy mode distinguishes paid, free, unavailable and unknown.
Management, registry schemas, catalogue rows and desired-state types expand in their scoped slices;
this foundation does not advertise a production-complete driver interface.

`AdmittedOperation` records frozen claim/generation/funding references, exact query, desired-state
digest, known authorized cost and required renewal pre-expiry. Its public constructor **does not grant spending
authority**. PR19/20 must verify durable engine admission/seal/funding/ownership and resolve validated
state before exposing any real write bridge; descriptor possession alone is insufficient. No
transport consumes it today. Separate fake writers only simulate call logs and check binding/action/term.
Read handles cannot be upgraded to writer handles. A known spending cap is not a retail cost floor.

## Outcomes and evidence

`DriverResult` preserves provider disposition separately from future local lifecycle. Acceptance
or pending requires sent evidence; rejected requires explicit unsent/provider-proof basis and
cannot carry direct or candidate charge evidence; reasons agree with dispositions.
Existing ownership may be prior context for an unsent renewal; it is not proof that this attempt executed. Discrepancy is retained, never a refund/retry signal. Unknown is not rejection.
There is no completion flag: later action-specific ownership/expiry/term/auto-renew/charge checks
own completion. Constructor consistency cannot prove the provider's rejection/correlation claim.

`Evidence` contains normalized server-only facts, no raw response or wire/contact/credential fields.
Array values are copied to sever external PHP references before freezing facts/capabilities.
Null money, premium, availability and domain flags mean unknown; known zero and false remain values.
NameSilo catalogue prices specifically require positive amounts: a provider-level zero price
quarantines that row as unpriceable before neutral quote evidence is created (see [catalogue.md](catalogue.md)).
Order candidates include binding/name/action/term/date/charge and optional opaque reference; zero
or multiple candidates never become unique financial proof. `AttemptEvidence` distinguishes absent,
recorded-null and recorded execution; any recorded execution refuses definitely-unsent attribution.
Attempt input also retains unknown/absent/present seal, optional Enom order/transfer reference and
expected post-operation expiry. Unknown/present seal or provider reference cannot become unsent.
`ReconciliationResult` binds observations to the exact frozen attempt, account, operation and query;
it rejects local unsent attribution, mismatched names/actions/terms and known-reference mismatches.
Only the engine can establish local unsent from its records. Reconciliation cannot write/replay. Read budgets bound the eventual wire adapter's candidate search.

Money retains decimal lexical precision and currency without float arithmetic. Quote evidence keeps
source, observation/validity and annual versus exact-term units; an annual amount cannot impersonate
an undocumented multi-year total. Date evidence preserves date-only versus offset-bearing instant
and provenance, validating zone against offset; unknown timezone is never invented for date-only expiry. No cost floor is encoded.

Result casts/debug/JSON carry summaries only; the explicit `evidence()` accessor is server-only.
Serialization and cloning are refused so evidence cannot silently disappear or enter logs/jobs.
An encrypted storage adapter must explicitly persist the needed facts in later engine slices.
Individual fact DTOs contain private financial/domain information and must not enter customer views.

## Evidence limits

Fake Enom/NameSilo tests establish type invariants, bound routing, budgets and uncertainty handling.
They do not prove real provider mapping, contacts, account ownership, admission, actual spending,
registry completion or native lifecycle compatibility. Driver adapters and management capabilities
must carry the retained Enom scenario inventory and separately verified NameSilo acceptance gates.
