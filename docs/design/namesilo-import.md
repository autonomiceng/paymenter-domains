# NameSilo import

Dormant adapter for the existing shared preview/commit path. No bootstrap, routes, account
registration, processing flags or writer ports are changed. Enom keeps its import reader.

`NameSiloAdoptionReader::factory($runtime, $limiter)` adapts the account-bound closure returned
by `AccountBindings::nameSiloRuntimeReaderFactory($binding, $clock, $readAcceptance)` to the
registry's existing `adoptionReaderFactory` argument. Activation must explicitly compose both
the domain-read account and this import source. The balance-only enrollment composition stays
insufficient. The import factory does not accept a raw key or construct a fallback transport.
The runtime retains exact account/site/credential-revision acceptance and physical call limits.
The existing runtime is Live-only; this work does not claim connected Test/Live acceptance.

The source exposes only `binding` and one-name `read`. It makes one interactive `getDomainInfo`
request, with a one-read/90-second context and the usual 25-second exchange limit. No price,
inventory, availability, contact, auth-email, order or mutation request is made. Timeouts and
unknown responses refuse without retry. Missing/malformed flags, invalid dates, non-Active
state, absent complete nameservers, registrar auto-renew on and expired/out-of-horizon dates
refuse. Shared mapping rechecks customer/service/account isolation around the unlocked read.

Successful account-scoped domainInfo proves ownership of the requested name. NameSilo has no
opaque domain reference to manufacture: it stays null through evidence and the committed
holding. Expiry remains a provider date with `namesilo_domain_info` provenance and no timezone
or fabricated instant precision. Privacy, lock and normalized nameservers are retained in the
encrypted adoption evidence. Contact profiles are not imported or associated. Commit retains
native IDs/locks, replay behavior and evidence, with no provider call, purchase or invoice.

Active state (`ordinary` in the shared import DTO) does not prove non-premium renewal cost.
`AdoptionRenewalEligibility` is the NS16 seam: imported NameSilo holdings cannot obtain new
manual or scheduled renewal debt, and the staff preview/modal does not promise a charge/date
or renewal. Existing NameSilo execution still requires genuine non-premium acquisition proof;
import fabricates none. No configuration flag or TLD catalogue price clears this restriction.
NS16 must integrate valid domain-specific renewal evidence with its accepted price model before
relaxing it. The existing catalogue/mapping prerequisite is retained pending that integration.
Owner do-not-renew intent and native cancellations are preserved independently.

Offline tests exercise the real admitted read runtime with a namespaced fake physical wire,
shared preview/commit, no-money/no-operation behavior, encrypted settings, nullable reference,
date precision, local replay and both billing refusals. They establish no provider acceptance.
