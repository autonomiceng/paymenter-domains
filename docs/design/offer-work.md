# Dormant offer work

`Catalogue/OfferWork` owns the new path's account scheduling in `domains_observation_queues`.
It is not registered, scheduled or called by current staff/public/holding flows. B6 supplies the
checked activation and routes triggers here; legacy fill/sync work remains untouched until then.
B6 must not run this seam beside the legacy `ObservationWork` consumer on shared queue rows.

- `sync(binding, origin)` coalesces manual/daily feed requests. A failed run keeps its retry time;
  another Sync does not start overlapping reads or reset failure backoff.
- `requestFacts(binding, ending)` queues only a discovered account/ending, including an unpublished,
  unpriced suffix. Hints coalesce and reuse warm facts. Unknown facts never authorize selling.
- `wake(binding)` starts bounded local republication after rules/defaults/fees change, with no provider
  request. `tick(accounts)` is the single worker entry; `status` returns safe local progress/errors.

The required feed factory supplies B3 adapters through existing admitted transports, including their
per-wire daily call/spend caps and binding checks. The required allowance callback supplies **our**
remaining daily headroom for the origin, reserving capacity for existing holdings. There is no permissive
default. Pass the shared `AccountReadBudget` with the existing background restriction; the worker reserves
the complete group and passes a non-duplicating limiter, exact request budget and lease-bounded deadline.
These are our loop-prevention caps, not Enom or NameSilo limits. Feed/facts counts come from adapters:
five/one feed reads, two/zero independently verified fact reads. No registrar switch lives in the worker.
Caller authorization/readiness remain required Writer ports; these internal methods are not HTTP actions.

Queue state owns generations, claims, attempts, retries and safe success/failure timestamps, including
lazy per-ending requests. Provider facts retain their independent source time/freshness on the offer.
A price feed never refreshes metadata. Errors preserve both good observations and prior success age.
Deferrals retry after 120 seconds without inventing a send; failures back off 120/600/3,600/21,600 seconds.
Successful feeds are due daily; requested facts become due at their own expiry. No mandatory fact sweep
or per-term price reads exist. Zero-reader adapters report unsupported facts with no retry deadline;
NameSilo facts remain unknown without a wire request.

Each turn rotates accounts fairly, scans at most 25 offers for local republication, applies at most 25
buffered observations and reads at most one provider group. A pending feed may also service one facts
group, so a cold search need not wait for the full account buffer. Feed/facts alternate when both are due.
One account's error does not interrupt another account or the existing holding observation queue.
The local feed/request safety bounds are 5,000 suffixes and an 8 MiB encoded feed buffer.

Whole feeds are buffered before writes; pending observations survive restart in the retained queue.
Syncs during provider reads coalesce; manual Syncs after stored success or during buffer drain
remain pending for the next feed. After a 300-second claim expires, read-only work can be reclaimed with a new
generation. Already committed equal observations are no-ops. Binding/generation/lease checks run after
reads and again under Writer's native-first locks; stale workers cannot save observations or acknowledge
success. All catalogue changes, including unpriced discovery, go through `Writer::apply`. There is no
acceptance-list copy, SellRow/fill write, cheapest-seller routing or activation here.

The additive migration changes only retained queue columns. Existing B2 preflight remains valid;
installation verifies recorded B5 storage and refuses lost/drifted columns. The focused SQLite regression
uses real queue/writer/native transactions and synthetic wire replies. It proves sequential recovery and
fencing, not concurrent MariaDB locking, installed cutover or provider acceptance.

## Production ports, still dormant (B6b split)

`OfferPorts(AccountBindings, Clock)::worker(Writer)` composes the existing admitted Enom transport,
B3 feeds and B5 account budget. It is deliberately absent from Runtime and scheduler registration.
The caller cutover and checked activation remain unfinished; constructing this factory grants neither.

NameSilo composition requires #386's `AdmittedTransport`, `NameSiloLiveReservationPolicy` and their
reservation dependencies, plus #387's `AccountBindings::credentialRevision()` and
`LocalAccountEnrollment` validation/storage contract. They are read dependencies, not merged here.
Missing dependencies refuse reads. The NameSilo credential callback accepts only reads, reloads
verified encrypted credentials and requires the captured opaque revision before each physical send.
Enom's wrapper likewise checks current verified credentials before delegating to its admitted wire.
The Enom revision is a local keyed digest; NameSilo uses #387's persisted opaque revision. Neither is
an account identity or provider confirmation. Transactional checks use current locking credential rows.

Both interactive and background catalogue requests share **our** 30% daily call allowance and the
25-read account minute budget, reserved atomically for either origin. Checkout keeps its normal
interactive allowance. The factory narrows the existing admitted transport scope to 30% of our daily
cap: each physical admission checks that threshold atomically against all origins' usage. A prior
allowance snapshot grants no send authority. Nested scopes keep the stricter share and restore it
after refusal; other background callers keep their default half-day share. Feed iteration completes
inside that scope. These are our configured limits and headroom, never registrar limits. No provider
I/O runs while database locks are held.

Production workers capture the credential revision on each turn and recheck it after reads and under
Writer's locks. Durable feed buffers carry that revision. Rotation discards an older buffer and queues
a fresh feed; it cannot publish or acknowledge an in-flight response from older credentials. A refused
stale claim can expire normally before recovery. The optional worker port keeps existing dormant B5
fixtures compatible; a production worker must be constructed through the credential-fenced factory.

Focused offline tests cover Enom factory composition without sending, explicit fake-wire fencing,
shared local headroom, buffered rotation and rotation during a feed. NameSilo dependency integration,
concurrent installed locking, caller agreement and exclusive-consumer activation remain unproven.
