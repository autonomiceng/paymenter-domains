# Domain management (slice29)

Internal, dormant capability ports; no routes, presentation, scheduler or production sender.
Source: Enom `444f31ce0bfd07216e5200544bffe863119d0e7d`, accepted fixes through
`f7175ff`, MIT copyright Autonomic Engineering. No provider acceptance is established offline.

`DomainManagement::request` authorizes the current native owner or freshly locked
`admin.domains.manage` staff. It freezes actor/reason/input/account/generation and an idempotency
key in a separate operation. Unsupported/unknown capabilities refuse. Holder processing and
native service/annual route/reservation/cancellation checks do not depend on today's seller.
DNS/SRV/DS require `domains_dns_tools` (default false); management writes require the independent
`WriteFeatures.management` and account acceptance (both default false).

One bounded preparation window runs outside locks. Current owner, staff permission, native
funding, lifecycle, configuration and all original desired inputs are checked again before a
single seal. The exact live request/context/driver grant is consumed once immediately before
one write. A descriptor cannot authorize itself. The account slot is shared with acquisition
and renewal. Paid privacy refuses unresolved account spending instead of assuming an in-flight
charge is zero. Unknown cost/balance refuses sending; no retail/cost floor exists.

Every write obtains fresh owned readback for its named section. Email acceptance means only
that the provider accepted an email request, never delivery or a displayed code. A ten-minute
cooldown covers owner and staff requests. Successful normalized facts are retained encrypted in
append-only `attempt.management_reads`, with action/key/generation/time; original acquisition
`attempt.corrections` remains unchanged. Completion does not advance domain expiry, native
service expiry or the renewal billing anchor. Unknown after sealing never re-enters dispatch.
Explicit `reconcile` performs bounded reads only, records at most20 observations, and cannot
replay a write/email. Expired sealed workers become uncertain; expired unsealed workers become held and definitely unsent, even with writes disabled.

Paid privacy creates one positive USD native null-reference invoice line using locked
`domains_privacy_price` and native exclusive tax once. It is independent of domain renewal
and cannot activate or annualize a Service. Issued routing/price/membership is immutable.
Sending requires the entire native invoice's current net succeeded funding. Purchase expects
one year from dispatch day (source ±one-day date tolerance); renewal requires the originally
observed privacy base plus exactly one year. A captured original order ID and full-year
readback are both required. Fresh GetOrderDetail must match that captured ID, one domain/item,
successful/closed status and exactly one year before confirmation. Acknowledgements are retained before readback; missing original acknowledgement cannot be invented by observation.
Read-only resolution requires current readable binding, independently of write gates, staff rights or lifecycle pauses.
A paid, held, definitely-unsent request can use slice26's audited refund-once account credit;
a sealed/unknown request cannot. Refund retains the acquired domain and native financial history.

## Source scenario inventory

| Frozen source behavior | Target test or explicit disposition |
| --- | --- |
| DomainManager/DomainOperations owner, staff, stale permission, active service, unresolved sibling and duplicate request | `management-test`: stranger, staff revocation, native drift, repeated key/write, completed evidence; native holding checks reuse24/25 |
| Empty nameserver list / UseDNS=default | Explicit reset-to-provider-default command is unsupported here;33 must expose a named, separately authorized action before implementing it. Empty lists never silently change DNS. |
| ModifyNS/GetDNS canonical hosts and Enom default nameserver equivalence | `management-test`; source-equivalent `.name-services.com` sets are normalized before readback comparison |
| Contacts one role/all four in one request; optional fields; collected roles; malformed/conflicting aliases | `management-test`; `Contacts::read` and existing `enom-read-test` retain attributed fields/NotCollected/conflicting aliases; no account profile mutation |
| SetRegLock/GetRegLock and provider renewal-off | `management-test`; nullable flags never fabricate desired state; customer provider-renewal enable remains unsupported |
| epp_email success/unknown/cooldown; epp_sync acknowledgement | Email action tested with cooldown; acknowledgement-only sync has no retrieval result and is explicitly unsupported. No plaintext auth-code capability |
| billPrivacy/purchase/renew/full-term expiry/unknown/unsent refund | `management-privacy-test`; source invoice retail semantics retained; provider spending uses fresh known cost/balance |
| EnableServices/DisableServices existing subscription | `management-privacy-test`; toggles have no invoice; free privacy ports use the same actions without paid assumptions |
| SetHosts complete1–50, all source types, MX-only preference, TXT bytes, stale preimage | `management-records-test`; `ManagementInput` retains A/AAAA/CNAME/URL/FRAME/MX/MXE/TXT; zero-record clearing remains refused; empty preimages require explicit DomainServices.HostRecords=true |
| SetDomainSRVHosts service/protocol/target/uints/optional HostID; unordered readback; explicit empty srv-records container | `management-records-test`; no NameSilo SRV mapping is inferred |
| Add/DeleteDnsSec tuples; zero/single/repeated KeyData; count/duplicate/mixed/error refusal | `management-records-test`; accepted source algorithm/digest sets retained; unknown schemas refuse |
| Glue child-domain validation; public IPv4/IPv6 CIDRs; add/update/delete, fresh old IP, binary IP equality; unknown≠absent | `management-records-test`; only exact known545 missing-host response proves absence; known/unchanged/missing preconditions refuse unsent |
| Unknown mutations/read-only reconciliation/no refund or replay | All three new suites; missing original paid ID remains unresolved |
| Snapshot refresh/known-glue merge, safe client DTOs/masked email/CSRF/confirmation fields, staff forms, jobs |31 owns shared refresh and projection from retained facts;33–36 own presentation, forms and staff workflows; no public DTO or installed job is introduced here |

## Contract for30b and later gates

30b must implement `ManagementProtocol`: account-bound capabilities, local action validation,
bounded named `prepare` and read-only `observe`, and one `apply` write through the protected
single-use `write` method. There is no public arbitrary command sender or default network
transport. Preserve the context's interactive/background origin, deadline and per-wire budget.
Declare `PrivacyMode::Free` and refuse paid privacy purchase/renew; free toggles must not create
invoices. The shared `ManagementPayload::normalize(action,payload,name,registrar)` defines canonical shapes; adapters narrow supported types and limits. Whole-list DNS and provider-specific absence proofs remain adapter-specific.
NameSilo contacts require copy-on-write customer profiles, non-default readback and asynchronous
association confirmation under the existing18 profile authority. Profile creation/readback must have
its own durable, freshly authorized one-use grant before the separate association operation;
this port permits exactly one write, so30b must add a named profile staging boundary and never
put two writes into `apply` or mutate a shared/default/customer-supplied profile. Unsupported advanced DNS/DS/glue
stay explicitly unsupported. Auth-code email must not claim delivery or display a code.

31 owns account-fair bounded scheduling, shared observation/projection, durable correction
work and read-budget allocation. No scheduler may replay sealed management operations.
33–34 own owner routes, CSRF/idempotency forms, safe DTOs and capability-specific labels.
35–37 own fresh native permission registration, financial actions, held dispositions and audited
account/TLD acceptance.38 owns alerts/delivery.39 must bind one feature/acceptance instance,
secure injected transport, shared atomic cache, native guards and queue/lease/deadline policy;
prove paired MariaDB locking/current reads and installed compatibility before enabling writes.
Provider identity, origins/rates, Enom management payload/readback vocabulary and price units
still need controlled provider acceptance. No registrar or RDAP request was made for this slice.

The seal, current funding check and atomic account slot are committed together by `consume`,
immediately before the single exchange. Preparation failures and rejected consume remain unsealed.
Queued, held and unpaid definitely-unsent management requests do not block renewal. `retire`
requires current owner/staff permission, matching frozen identity and no seal; unpaid privacy
is owner-only, requires an untouched native pending/cancelled invoice, and cancels that invoice.
Paid or financially touched requests cannot retire; funded held requests use slice26 refunds.
Paid privacy requests require enabled management gates and the owner before any provider read/invoice.

Neutral inputs: nameservers list; contacts role/contact using18's field schema; lock boolean;
privacy/email/renewal-off empty input; DNS/SRV records plus preimage_digest; DS tuple;
glue child host and public IP (delete host only). `ManagementPayload::digest(rows,kind)`
hashes sorted canonical records, preserving TXT bytes and ignoring SRV provider host IDs.
Result facts: nameservers, locked, contacts/collected, auto_renew, privacy allowed/exists/enabled/
auto_renew/expiry/cost, records (DNS/SRV/DS), or glue host/IP. Facts stay encrypted and are not DTOs.
Acknowledgement IDs are retained only through the live sent grant, never from observation facts.

31/33 must link each separately authorized correction to the original operation key and named
immutable `attempt.corrections` section, then append separate progress. This slice supplies the
execution capability but does not claim automatic correction fulfillment or alter original proofs.
Late financial activity on retired privacy invoices requires35–37's reviewed disposition;
retirement is never permission to discard native financial history or dispatch another write.

Documented Enom validation/policy/glue refusals are normalized separately from unknown errors.
A documented nonfinancial refusal is terminal rejected when readback does not confirm or is
unavailable; it retains sealed provider-refusal evidence and releases peer/renewal blocking. Any sealed uncertain operation,
including nonfinancial actions and auto-renewal-off, still blocks its own domain until resolved;
it never blocks spending on another domain merely because it is nonfinancial. Paid privacy
refusals remain financially uncertain: binding report26 explicitly tightens source behavior to
forbid any sent/possibly-sent credit, and28 retains that rule. No refusal label creates unsent proof.
Account spending checks include paid invoices/acquisition/renewal spending only. Slot availability
is checked before claim and acquired at consume. A temporary postclaim slot/spending conflict
retains up to five append-only unsent deferrals and may be claimed again only before any seal;
old contexts fail changed-stamp validation, and the immutable claim identity/history is retained.
Beyond the bound, a held definitely-unsent request requires explicit disposition/refund. Unknown
or expired claims never gain this retry encoding. Email cooldown is rechecked at consume.

35–37 must explicitly disposition funded requests still queued/awaiting-payment after gates or
authority revoke before claim, including unsent deferrals. They cannot retire as unpaid, and
require an authorized held transition before slice26 credit. Caller reason is allowlisted for
internal compatibility only; audit reason is always derived from current actor ownership.

36 must define an audited, action/key/generation-bound staff disposition for sealed-uncertain
management, including unacknowledged auth email, exhausted observations and paid refusals.
It may release domain/renewal peer blocking, but must never imply definitely-unsent proof,
a refund, resend or financial settlement; paid financial uncertainty remains separate.
39 must not enable management writes until this disposition and its evidence verifier exist.
30b must preserve every frozen desired-input key in preparation: Enom only enriches glue
`old_ip` and paid `privacy_terms`. Before another adapter is accepted,30b must add engine
comparison of all other prepared keys against frozen input and an adverse-driver regression.
35–37 must separately define evidence-backed financial disposition for account-wide spending
holds caused by paid uncertainty; releasing a domain/renewal peer block alone grants no account
spending authority.39 must test domain, renewal and account-spending blocks independently.
39 must verify WPPSAllowed remains true after purchase/renewal for this strict readback contract;
otherwise revise the accepted adapter contract explicitly before enablement, never fail open.

## NameSilo management (slice30b)

The account-bound NameSilo port declares free privacy and supports nameservers, contacts,
lock/unlock, privacy toggles, auto-renewal off and auth-code email. Paid privacy, advanced
DNS/DS/glue and auth-code display refuse before I/O or billing. Interactive calls use `/api`,
background/reconciliation `/apibatch`; the injected sender inherits deadlines, budgets and the
1 MiB response bound. Preparation preserves every frozen desired key; the engine permits only
Enom's existing `old_ip` and paid `privacy_terms` enrichment (base remains frozen; renewal expected date follows that base, purchase target follows dispatch day).

Contacts require two explicitly authorized operations. `contact_profile` takes role/contact,
creates one non-default profile, retains its original acknowledged ID and exact field readback
in owner/account-bound encrypted evidence. `contacts` takes the same role/contact and reserves
that stage once, internally freezing its operation key; callers cannot supply a profile ID.
Association requires durable creation proof plus fresh named non-default field readback,
then spends its own single grant on `contactDomainAssociate`. Delayed association confirmation
uses read-only reconciliation without reusing either write grant. Profiles are never updated,
shared or selected by caller ID; lost creation acknowledgement cannot be reconstructed.

An explicit `correction` acquisition key authorizes only separately requested NameSilo
nameserver/contact/privacy/auto-renewal corrections matching the sealed original input. Current
native net payment, owner/staff permission, holding/account/generation and lifecycle are checked
again before seal, including while an owned transfer remains pending for correction. This does
not complete, replay, settle or modify the acquisition, its corrections, expiry or billing anchor.
31/33 still own correction scheduling, named-section projection and separate progress records.

All response vocabularies remain synthetic acceptance gates for39, including exact profile
shape/default flags, asynchronous association outcomes and TLD privacy eligibility. Email success
means request acceptance only. Readback failure/unknown sends remain unresolved without replay;
36's audited uncertain disposition remains a prerequisite to enabling management writes.
Completed, key-linked correction siblings may pass acquisition reconciliation's peer check only
with matching management evidence; independently proven original charge/ownership/expiry/flags
still control acquisition completion. Definitely-unsent linked corrections (queued, held or retired) also release
that peer check; consume still checks current acquisition authority. Sealed uncertain corrections
continue to block, pending36 disposition. Unrelated management does not gain this exception. Idempotent association retries
reuse the originally frozen stage even when a newer matching stage has since been created.
Contact staging/association recheck an explicitly empty supported ordinary registry schema.

NameSilo support reply 2026-10-18: registered-nameserver glue is IPv4 only for now;
any IPv6 glue input must refuse before a write. This adapter leaves all glue unsupported,
including IPv4. There is no provider idempotency key: unknown-after-send stays read-only
reconciliation and never replays. Slice31 must enforce per-account `/api` concurrency1 with
at least1-second spacing and `/apibatch` concurrency2 (conservative end of the stated2–4).
This slice adds no scheduling and assumes no higher concurrency. Gate39 still owns acceptance.

The31/39 shared per-account exchange limiter must apply those limits to every wire request,
including multiple reads/writes within one management operation and registration/transfer flows.
Gate39 and any future NameSilo glue slice must retain an explicit IPv6-input rejection test
before write admission, even if the glue capability is later enabled for IPv4.
