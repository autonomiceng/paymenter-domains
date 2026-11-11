# Local account enrollment

NameSilo can use explicit local enrollment instead of a provider identity verifier. Set
`domains.namesilo_account.environment` to `test` or `live` in deployment configuration, then use
the existing reviewed Connect action. The old OTE-only enrollment switch and this composition
are mutually exclusive. Domain readers and spending remain separate deployment work.

One bounded interactive `getAccountBalance` read authenticates the submitted API key. No default
contact email, account ownership or provider account ID is inferred. The stored identity is a
random local identifier; a credential hash never supplies identity. Enom retains its existing
provider verification path.

The account, registrar and environment remain immutable. Credentials, enrollment metadata and
audit are encrypted. Rotation keeps the local identity, account fingerprint, holdings, budget
lineage and confirmed live site, and pauses processing. The same currently enrolled key cannot
create another local account in the same environment. Because the provider supplies no identity
proof, different keys cannot establish whether two local enrollments refer to the same provider
account; operators must rotate an existing account rather than enroll it again.

Authentication runs outside locks. The actor-bound single-use review rechecks its original
settings snapshot before saving; concurrent changes refuse the save. Local enrollment requires
this review even for direct callers. Live readers still require explicit HTTPS site confirmation;
a copied site cannot rotate the original account. An existing balance-only OTE enrollment can
authenticate its current or replacement key through this path in TEST. It retains its original
identity, encrypted enrollment facts, holdings, held requests and usage; processing stays off.
Original OTE facts prove retained lineage, while current local facts validate the replacement key
and credential revision. Another TEST account cannot enroll either its original OTE key or its
current key. The old balance-only composition still requires its original key and cannot read a
rotated local enrollment.

`AccountBindings::credentialRevision(binding)` captures the current opaque local credential
revision. Credential-bound work must recheck it with `requireCredentialRevision` before saving or
sending; rotation, direct credential edits and a changed live site invalidate stale work. This
seam does not install a NameSilo admitted transport or enable a feature. Local enrolled accounts
use configuration checks instead of default-contact-email acceptance; per-owner contact/profile
proof remains required by the existing operation engine.
