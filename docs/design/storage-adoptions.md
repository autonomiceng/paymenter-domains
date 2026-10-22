# Retained adoption and invoice-history storage

These tables are storage only. No route, staff action, scheduler, billing, management or
observation consumer reads them. A stored `committed` state is not verified holding authority.
Holding-anchor validation and integration are deferred until the shared native identity lock
interface supports adopted holdings. No checkout, funding or operation evidence is fabricated.

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
`evidence` with version1. It is append-only: ordinary edits, numeric updates and deletes refuse.
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
