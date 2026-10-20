# Retained adoption and invoice-history storage

Adoption and invoice-history tables retain their original evidence. Domain attachment and
renewal consumers validate committed adoption under the shared native identity prefix.
A stored `committed` state alone is not holding authority. No checkout, funding or acquisition
operation evidence is fabricated. Confirmation and invoice-history linking remain separate workflows.

| Migration | Table | Retained identity |
| --- | --- | --- |
| `2026_10_19_000010_create_domains_adoptions` | `domains_adoptions` | Unique `request_key`; unique `(domain_id, authority_generation)` for committed projections. |
| `2026_10_19_000011_create_domains_history_links` | `domains_history_links` | Nonnull unique `link_key` for exact domain/invoice/nullable-item identity. |

Both migrations retain all rows on `down()` and uninstall. `Installation::SCHEMAS` verifies
both declarations, including column metadata and unique indexes, after migration skips.
Preflight refuses retained tables without their migration records and recorded missing or
drifted tables before running later migrations. No native schema, foreign keys or cascading
deletes are added. The existing holding provenance column is unchanged.

## Adoption requests

`Adoption` stores the request key/digest, nullable preview domain ID, required native service,
user and server IDs, registrar/environment/account fingerprint, state, origin, encrypted
`evidence`, actor/reason, do-not-renew intent, nullable native cancellation ID, observation and
validity times, nullable commit time, nullable authority generation and timestamps.
States are `preview|committed|refused|superseded`; origins are `staff|converter_pending`.

`authority_generation` is populated only for committed rows, with a positive domain ID and
commit time. Its value must equal encrypted `evidence.resulting_generation`. Preview, refused
and superseded rows keep that column null, so multiple previews for the same proposed
holding/generation coexist without consuming the committed unique key. Proposed generations
remain in evidence. Request key uniqueness applies to every state.

The model requires a transaction and canonical lowercase ASCII request keys matching
`[a-z0-9][a-z0-9_-]{0,127}`. IDs must be canonical positive integers. New rows start as preview
or refused; committing always locks a saved preview. Saved preview identity, evidence, digest,
actor, reason and cancellation intent are frozen. A preview may become terminal once; a previously absent
domain ID may be assigned only when committing. Existing domain IDs cannot change.
A cancellation ID is assigned only on commit, and is required exactly when do-not-renew is true;
preview/refused/superseded rows have none. Native cancellation validation belongs to the future workflow.
A current locked row comparison refuses stale preview overwrites. Terminal rows are immutable;
ordinary deletes and numeric updates refuse. Raw SQL, bulk Eloquent writes, quiet saves and
trusted PHP bypasses remain privileged maintenance, as for the existing ledgers.

`evidence` v1 retains canonical name; native owner/service/order/product/annual-plan/domain
property identity; registrar/environment/stable-account proof digest and binding; provider
domain ID where supported; provider expiry precision/timezone/source; explicit auto-renew false;
ordinary/hold/redemption flags; read start/end; command-set version; accepted credential
revision; retail price row/revision/currency/tax basis; prior and resulting generation/revision;
source conversion row ID; and `history_link_ids`: always a sorted, de-duplicated list of
`{invoice_id, invoice_item_id|null}` pairs, never link-row primary keys, whether or not the domain
already exists. Sort by invoice ID then nullable item ID (null first) and freeze this canonical list
in `request_digest`. The discriminator is `version=1`.
`request_digest` must freeze the requested identities, cancellation intent and actor/reason.
The future adoption workflow must validate and derive this complete normalized evidence and
digest, including preview freshness and the maximum 120-second validity window; this storage model checks the version, registrar/environment consistency and committed generation
projection only. Future holding-anchor validation must verify every binding and evidence match
before granting holding authority.
Never persist credential paths, secrets, HMAC values, raw replies or contact bodies in evidence.
Request keys/digests, account fingerprints, evidence and reasons are hidden from generic output.

## Native invoice history

`HistoryLink` stores domain/service/user/invoice IDs, nullable invoice-item ID, source
(`native_service|staff_verified_import`), linked-by actor, reason, linked time and encrypted
`evidence` with version 1. It is append-only: ordinary edits, numeric updates and deletes refuse.
`link_key` is SHA-256 of the JSON array `[domain_id, invoice_id, invoice_item_id]` using integer
IDs and explicit JSON null. The model derives it and rejects inconsistent supplied values;
invoice-only links therefore remain unique despite their null item. Distinct items, invoices
or domains have distinct identities. Evidence, reason and link key are hidden from generic output.
The future adoption linker resolves frozen requested invoice/item pairs by deterministic `link_key`
on commit and replay; it must not require `evidence.adoption_id`. Reuse an existing link only after
validating its exact domain/service/user/invoice/item association and native relation/import proof;
otherwise refuse. Never rewrite its immutable evidence. Only newly created adoption links record
the adoption ID/request digest in encrypted evidence; no plaintext adoption pointer is needed.
Whole-invoice and item links may coexist in storage; the future linker decides whether a request may include both. Each insert is atomic; multirow native
validation and commit belong in the future workflow transaction.

The future linker must prove invoice ownership and exact item/service relation, or record an
audited imported mapping, before insertion. These models do not perform native relation or
permission checks, rewrite invoices/payments/credits, imply settlement or make historical debt
eligible for funding, reconciliation or refunds. No funding/settlement flag is stored.

Offline SQLite fixtures cover uniqueness, encryption, frozen intent, append-only history,
schema drift and retained ciphertext on lifecycle operations. Provider proof, native relation
validation, concurrent MariaDB lock behavior and adoption activation remain separate gates.

## Holding attachment

Migration `2026_10_19_000014_add_holding_adoption` adds nullable retained
`domains_domains.adoption_id`. Installation checks its recorded history and complete
foundation declaration, including when migrations skip. Checkout holdings keep it null.

`Domain::attachAdoption` is a transaction-bound storage method for a future confirmer.
It requires the live `HoldingIdentityLock`. The extension prefix locks server, account,
server settings and TLD before the domain and committed adoption. A future confirmer
must call `nativeIdentity($domain)`, lock the domain, then commit the adoption and
call attachment in that same outer transaction. Committing the adoption first would
reverse the lock order. Attachment may re-read rows already locked by that prefix.
It derives `snapshot.holding` v1 from frozen adoption evidence and the retained native
prefix; it accepts no caller-supplied holding identity. The committed reverse domain
link must match. Attachment advances the pending generation exactly once and writes
pointer, adopted provenance, original identity, revision, expiry and initial billing
anchor together. Prior and resulting revisions must match the pending revision and
its immediate successor. Active status records the committed holding; it is not a
freshness decision. A3 must refuse past-expiry proof before committing. This method
grants no permissions, does no fresh provider read and
is not a confirmation workflow. The future confirmer owns those checks.

Ordinary saves cannot attach, replace or clear the pointer or holding snapshot. They
cannot change provenance or generation. Checkout proof remains frozen and cannot be
promoted as adoption. Numeric domain updates refuse. Observation metadata, current
confirmed revision/expiry and current billing anchor may still evolve; the original
holding snapshot stays byte-for-byte equivalent after decryption. Privileged raw SQL,
and bulk writes remain outside these guarantees. Quiet saves still pass the
retained-identity checks in `save()`; event-free creation is not an import API.

## Renewal identity and anchors

`HoldingLocks` uses the shared ordered native prefix for both sources. It captures
one normalized native identity from current locked owners, all relevant orders and
services, the annual product/plan, domain property, route and TLD markers. It then
validates either genuine checkout evidence or `snapshot.holding` plus its committed
adoption row. The rows, encrypted source and normalized identity enter the repeated
stamp. Adoption rows follow the domain and precede operations; no earlier native
row is acquired after either ledger row.

Adopted authority requires both pointer directions, the exact original identity,
matching live account/registrar/fingerprint, generation, provider ID and evidence
digest. Matching TEST evidence is still ineligible. Register is the structural
native route only. Adoption supplies no purchase admission, cost or payment proof.
The acquisition invoice guard explicitly excludes adopted holdings.

`HoldingAnchor` has two renewal-only branches: `adoption` and
`completed_operation`. The first starts at the original adoption revision and
expiry. Later current revisions must be reconstructed from that immutable root
through each succeeded renewal, in ledger order. Every step requires the previous
expiry and revision, full original sealed completion evidence and the exact added
term. The current ledger and billing anchor must equal the resulting revision and
expiry. Editing current labels together cannot create a confirmed revision.

Retained operations from the previous generation count as resolved history only
when their complete raw-row hash matches the original adoption evidence and every
row still has definitely-unsent terminal proof in that generation. They cannot
supply an acquisition or renewal anchor. Changed historical evidence requires
staff review. Paid and complimentary renewal paths share these checks.

Observation saves may update observation metadata and synchronization time. They
do not advance confirmed revision, provider expiry or billing authority. A verified
renewal completion advances exactly one revision and records a completed-operation
anchor. Subsequent renewals use that branch while revalidating the original
adoption and the entire confirmed chain. Original pointer, holding snapshot and
adoption evidence remain unchanged.

Unreadable adoption evidence fails closed with no checkout fallback. Staff facts
show an explicit unavailable/staff-review state derived from retained ciphertext;
no replacement proof or automatic repair is written. Restoring encryption access
is required before authority can be evaluated again.

The internal attachment and renewal consumers do not supply adoption confirmation,
permission checks, fresh confirmation reads, cancellation decisions or activation.
Those remain responsibilities of the future confirmer. NameSilo adoption/provider
acceptance and the existing native ownership-race activation gates remain open.

## Adoption activation limits

Do-not-renew adoption records do not grant this renewal authority. The stored
intent must match encrypted evidence, and a false intent must have no cancellation
ID. Qualification of a committed do-not-renew holding and later resumption remain
with the confirmation and cancellation workflow.

Before activation, that workflow must also integrate `StaffHoldingReview` resume
and reprice actions and `NativeCancellation` guards with adoption authority. They
still use completed/acquisition operation checks and refuse an initial adopted
holding. This change does not enable a confirmation action. Native cancellation
creation must occur in the ordered native prefix, before locking domain/adoption;
creating it after those rows would reverse the lock order.

Shared holding identity also permits existing management and observation consumers
to inspect an attached live holding. They retain their current permission, feature
and spend checks. Activation must qualify those paths independently; successful
renewal fixtures do not qualify management writes or observation scheduling.

The chain deliberately refuses corrected or unusual expiry increments that are
not exactly the recorded added term, and charge discrepancies remain reviewed
outcomes. No staff re-anchor or provenance rewrite is provided. A future reviewed
correction workflow must preserve original evidence and establish new authority.

Original acquisition refunds are not authorized by adoption evidence. Existing
refund checks require their original generation and genuine checkout/financial
proof. History linking alone cannot grant that authority or bypass those checks.

NameSilo adopted renewal still refuses at admission: original adoption supplies no
nonpremium purchase fact, so the NameSilo cost check cannot establish authority.
Enom uses the fresh provider premium fact. NameSilo needs a separately qualified
cost/premium source before activation.

Live-environment and do-not-renew checks currently belong to shared holding
identity validation. They therefore refuse TEST and do-not-renew holdings for all
holding consumers, including observation, management, refunds, unpaid retirement
and staff review. Before activation, split structural identity validation from
renewal eligibility and qualify each consumer's intended policy.

## Fresh-preview confirmation

`AdoptionCommit::confirm` owns the outer transaction and requires the default-off
`domains.adoption.enabled` deployment feature. There is no staff setting that enables it.
Fresh adopt, domain-manage and native service-update permissions join preview/view
permissions. History additionally requires invoice-view. The locked staff role is
current; request keys remain actor-bound and the complete evidence digest is immutable.

The A0 identity context retains sorted native Orders before Services, then native
products/plans/cancellations/properties/tax. Its prepared extension prefix retains
server/account/settings, TLD and renewal prices before domain and ordered operations,
then the adoption row. Attachment reuses that prepared identity without acquiring
an earlier lock after a ledger lock. Confirmation repeats preview capture on these
current rows, compares its digest and checks both the saved deadline and a maximum
120-second evidence age. It never refreshes provider facts under locks.

New names reserve an internal pending row at generation zero inside this transaction,
then attach verified generation one before commit. The transient row is never a
purchase or an externally visible pending conversion. Converted pending rows retain
ID/service/customer/account and advance generation exactly once; a missing reservation
is acquired through the same unique key. Any duplicate, deadlock, timeout, callback
failure or stale proof rolls the whole transaction back. No native order, invoice,
payment, complimentary authority, funding or registrar operation is created.

Projection uses the reviewed account's annual retail with native exclusive tax once,
sets the existing service active and retains null native expiry. Verified registrar
expiry determines the renewal calendar; near-due/price concerns retain staff review
facts. Confirmation creates no invoice or extra registration year; subsequent ordinary
renewal billing may issue an invoice and apply credit when its gates pass. Identical committed
replay returns the original holding without repricing, generation change or new reads.
Different digest/actor refuses. A new preview of an already adopted name refuses.

This first confirmation boundary refuses history links and renewal-off intent until
their atomic workflow is qualified. Activation remains off: the native user-deletion
race, shared-database qualification, accepted provider contract and non-renewal
consumer policies remain open. NameSilo adoption remains unsupported.

The native holding page shows retained issue-window/cutoff and safe price review
notes. It warns about ordinary invoice creation and credit application after adoption.
