# Transfer inputs (slice27)

Adapted from Enom `444f31ce0bfd07216e5200544bffe863119d0e7d` TransferOrders,
CartOrdering and Rdap, retaining `d2c55e2`'s narrow unclean-close404 fix.
Reference checkout tip `f7175ff` was inspected; frozen444f31c supplies imported bytes.

Transfer availability uses the neutral reader's `availability(Action::Transfer)`:
`available=true` means transferable, with independent explicit `premium=false`.
Enom requires registered211 plus positive Transferable, with premium/platinum/EAP
exclusions. Slice27 structurally blocked NameSilo even with premium=0; slice28 below supersedes
that gate with explicit independent ordinary evidence and adds dormant dispatch/anchors.

TransferPrecheck requires an explicit account `domains_transfer_endings` allowlist
(default empty), annual term, ordinary ASCII identity and empty accepted registry
schema. Source unsupported uk/ca/eu/ai/de endings remain off; RegistryInputs also
blocks us and every missing/nonempty schema. Lock settings
`domains_transfer_creation_lock_days` and `domains_transfer_previous_lock_days`
default60, bounds0–365. Cached receipts retain the observed rules; checkout repeats
current locking rule reads after the seller/server prefix. Settings writers37/39
must take the common server mutex. No seller change repoints an existing transfer.

RDAP uses IANA bootstrap, no referrals, bounded HTTPS/DNS safety and a shared neutral
request budget/deadline. Only the selected domain endpoint404 proves not registered;
bootstrap404 and lookup failures remain advisory unknown. Exactly receive-error404
survives an unclean close; partial/write/timeout errors and other statuses do not.
The advisory text promises no credit for possibly-sent provider refusals. A cached
receipt is not dispatch proof;28 must repeat fresh provider/RDAP preflight.

TransferInputs is a dormant owner POST-body service, returning no code/model.
Codes preserve1–512 single-line bytes inside encrypted hidden payload only; query
parameters, GET, foreign owners, claims, seals, unknown/sent evidence and closed
states refuse. Errors discard internal exceptions, including captured closure data.
Before payment codes can change without starting a clock. After verified full native
funding, code replacement ends at paid_at+30days, at exact UTC seconds. Payment time
comes from the earliest native paid audit or unchanged successful transactions first
covering the complete invoice, never callback time. Datetime offsets are retained; raw
audit timestamps use Paymenter's configured PHP/database timezone before UTC normalization.
Missing/edited evidence refuses without inventing a paid clock; durable diagnosis and alerts
belong to35/38/39. The RDAP unknown flag stays in the receipt only;28 must freshly recheck
and persist dispatch provenance, and33/35 must present it without inferring from missing policy.
Replay and recovery never reset paid_at. Ordinary model edits cannot change codes or
payment time. No queued job/send follows code submission in this slice.

Contact correction18 stays held, now requires a verified unexpired paid transfer
clock, and preserves code, registry, nameservers and deadline. Registry edits remain
unsupported. Other held reasons, including registry404, cannot accept a new code.
Installed33/39 must exclude trimming, use native auth/CSRF, one-use session form keys,
throttling and safe nonflashing errors. No route/controller/UI/hook is installed here.

Explicit expireMissingCode(id) retains a transfer-specific definitely-unsent timeout
proof and closes the local domain/service atomically before invoking Refunds26. No claim/start/seal/provider/charge/fallback/unknown
fact qualifies, including null-valued evidence; a code ever retained prevents automatic
timeout credit. Current signed full-invoice funding and the exact native own line are
rechecked for each credit/retry. Credit and funding.refund are atomic/once; a failed
credit retains rejection for26's bounded retry. Reservations/invoices/payments remain;
paid transfer reservations cannot release before the account-credit marker commits, so
competing checkout cannot remove the identity required by a later credit retry.
only that transfer service cancels; unrelated cancellations never block it. No reservation release is a refund signal.
Contact/audit payload is retained under18/26 retention rules instead of source's wholesale purge;
no code exists on a timeout.35 owns explicit PII-retention/erasure policy and staff404 hold disposition.

28 owns dispatch, pending/terminal vocabulary, original charge/full-term expiry and
transfer-completed anchors.31/35 own fair scheduling, held readmission and policy tools;
35–37 staff permissions/workflows/acceptance;38 notification/reminder delivery;
39 sole installed hooks/queues, production acceptance, MariaDB paired races/EXPLAIN,
settings lock writers and the exact native payment-transition integration. No network,
installation, concurrency or provider acceptance is established by offline SQLite.

## Dispatch and observation (slice28)

TransferProtocol marks the transfer action on the existing one-use transport. A
registration permission, descriptor, profile handle or saved seal grants no transfer
send. The bound bridge requires the exact TransferPreflight/features/acceptance and
NameSilo profile service. Both protocols send one transfer write, with no retries or
ancillary mutations. Enom TP_CreateOrder retains PreConfig=0, Lock=1, Renew=0,
UseCreditCard=no and all four frozen contact roles; custom DNS/privacy are held.
NameSilo transferDomain uses a fresh non-default four-role profile, explicit privacy
and auto_renew=0, required external nameservers and opaque encoded auth bytes.

Paid awaiting-code operations with complete inputs can be explicitly executed. The
original verified paid_at must exist; existing saved codes remain usable after the
edit cutoff. Missing codes remain editable, without an attempt or account write-slot reservation. Two unlocked fresh
provider/RDAP observations precede the seal; current funding, setup, allowlist/lock
settings, acceptance, account and immutable desired state are rechecked under the
existing ordered financial prefix at seal and consumption. No seller dependency is
introduced. Transfer budgets allow12 requests (including two profile calls and the
initial RDAP bootstrap) within the same300-second execution/360-second lease;
every exchange remains capped at25seconds and the actual remaining deadline.

The original seal stores immutable transfer_admission: explicit premium=false,
name/year/cost, observed lock rules, base_expiry and rdap_unchecked. Missing RDAP
expiry stays null, with explicit unchecked provenance; it permits advisory dispatch
but cannot prove a subsequent added year. NameSilo's explicit availability premium=0
is independent ordinary-name proof; missing premium remains closed, regardless of
account prices or Active status. This supersedes slice27's structural NameSilo gate.

Enom observation accepts only a single original TransferOrderID and exact domain.
Header3/detail5 completes; header2 plus an enumerated source failed detail rejects;
source pending header/detail pairs (including draft5/detail100) remain pending.
Contradictory, missing, multi-domain, wrong-ID and unknown states remain unresolved.
TransferOrderID never becomes billing OrderID. An independently accepted encrypted
`domains_history_acceptance_transfer` contract (`*_transfer_v1`) must prove complete
USD history, timezone, lag and retention. Exactly one charged original domain/action/
year order inside the original send window, account ownership and full added-year
expiry (calendar clamp) are required together. Enom order expiry must corroborate
owned readback. Completion atomically records actual charge, native active/null
expiry, confirmed revision and the transfer billing anchor consumed by24/25. Original
ordinary acquisition proof remains available after later renewal anchors advance;
NameSilo renewal Cost remains closed if that proof or matching actual cost is absent.

Slice30a below extends NameSilo checkTransferStatus using vendor-module vocabulary.
Completed/Failed/Active strings, charge or owned expiry alone still cannot complete.
Fallback301/302 acknowledgements remain unresolved and never trigger replay.

A fresh domain-endpoint404 retains transfer_not_registered before sealing; generic
terminal encoding and code replacement stay closed for this staff35 disposition.
Only exact definitely-unsent pre-seal terminal proof encoded through
OperationTerminalProof qualifies for26 credit;27 timeout proof remains distinct.
Sent terminal rejection is retained with its reservation and an unresolved financial
alert, never credited or released. This deliberately tightens source sent-rejection
credit behavior. Five covered observations and audited bounded resume remain the22
policy;31/35 own fair scheduling/readmission,29/30 correction writes and38 delivery.
No installed composition, route, queue, scheduler, migration or production acceptance
is supplied.39 must verify shared cache slots, native locks, provider contracts and
paired MariaDB races;41 must positively map these retained proofs during conversion.


### Retained operational gates from slice28 review

Normal matching Pending currently consumes the inherited five-covered-poll cap and
raises an unresolved staff alert; twenty audited resumes bound lifetime observation.
31/35 must define a separate healthy-pending window, alert policy and fair account
budget before scheduling ordinary multi-day transfers. This slice is dormant, not
operational acceptance.31/39 also own staff recovery for lost Enom TransferOrderID;
no domain-only recovery exists. NameSilo live terminal acceptance remains39.

39 must accept strict baseline-plus-one-year date matching against old-registrar
renewal races, auto-renew grace, timezone boundaries and absent Enom order expiry.
These fail closed today. Twelve25-second requests exhaust the300-second execution
budget without the older ten-request margin; seal/send still require25seconds of
remaining execution time.39 must validate budget headroom against real timings.

26/36 must supply audited disposition for paid unclaimed held transfers with a
saved code: neither missing-code timeout nor the claim-bound terminal encoder can
refund them today. Sent rejections retain reservations indefinitely. This supersedes
27's proposed provider-proven sent-rejection credit gate:35/36 own disposition,
without treating sent evidence as unsent;31 owns filtering these rows from repeated
refund scans. Rejected status combined with owned readback or any charge candidate
remains uncertain with a contradiction alert, never terminal.


## NameSilo terminal vocabulary (slice30a)

Provenance: [official WHMCS module namesilo.php](https://github.com/namesilo/whmcs/blob/5afe3fa4657e9c6d28235f73035eaff7b4ebc53b/namesilo.php),
commit `5afe3fa4657e9c6d28235f73035eaff7b4ebc53b` (2026-10-01), TransferSync.
Facts only, no code copied. Confirmation is pending NameSilo support question7
(transfer status in the current support draft; question8 in the original parity list).
This supersedes28's pending-only implementation, not controlled live acceptance39.

The NameSilo transfer status list (account popup, captured 2026-10-18) supplies 28 exact
strings, retained alone in the test fixture: 9 pending, 1 completion candidate, 5 needs-action,
10 rejected and 3 deliberately unknown. `Transfer Completed` remains only a candidate.
`Wait Authorization Code` requires the owner to supply an authorization code;
`Domain is Private` requires disabling privacy or WHOIS masking at the current registrar.
Both reuse the existing nonterminal `transfer_needs_action` hold and staff alert, alongside
missing/incorrect codes and locked domains. `Transfer Timed Out`, `Time Out` and
`Transfer price changed` retain uncertainty and a staff alert because finality and charge
outcomes are unconfirmed. Case/space variants, empty, null and other strings remain unknown.
Needs-action holds remain observable without granting code edits, replay or refunds;
owned readback or any charge candidate instead retains uncertainty with a contradiction alert.
Sent rejection retains reservation/financial uncertainty; owned readback or any charge
candidate contradicts rejection and retains uncertainty/alert without a terminal marker.

Completion requires exact Active account readback, auto-renew off and frozen expected
privacy (dispatch's default off is frozen in transfer_admission), one accepted original-window USD transfer charge under22's no-ID history
completeness/lag/retention/timezone rules, and exactly one full calendar year from the
immutable pre-send expiry. Optional status `expiration` must be a valid date agreeing
with getDomainInfo; malformed or conflicting evidence stays unresolved with an alert.
Native service activation/null expiry, actual charge, domain revision and transfer
billing anchor commit together through slice28. Slice24 billing and25 renewal admission consume
that anchor; absent original sealed ordinary proof retains Cost, with no backfill.

30b owns separately authorized flag/profile/NS correction writes;31 owns fair observation
and the inherited five-covered-poll/20-resume pending policy.35 owns staff-only gates for
transferUpdateChangeEPPCode, transferUpdateResubmitToRegistry and
transferUpdateResendAdminEmail; none is implemented here.35/36 retain audited sent/held
disposition and permissions,38 delivery,39 provider/support/installed/MariaDB acceptance,
including strict added-year liveness, original-window clock skew and deadline headroom.
