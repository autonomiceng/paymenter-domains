# Transfer inputs (slice27)

Adapted from Enom `444f31ce0bfd07216e5200544bffe863119d0e7d` TransferOrders,
CartOrdering and Rdap, retaining `d2c55e2`'s narrow unclean-close404 fix.
Reference checkout tip `f7175ff` was inspected; frozen444f31c supplies imported bytes.

Transfer availability uses the neutral reader's `availability(Action::Transfer)`:
`available=true` means transferable, with independent explicit `premium=false`.
Enom requires registered211 plus positive Transferable, with premium/platinum/EAP
exclusions. NameSilo is structurally blocked even with premium=0;28 owns independent ordinary
transfer proof. No transfer writes or completion/renewal anchors are supplied here.

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
