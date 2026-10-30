# API foundation

`Registrars/NameSilo/Api/` contains the standalone NameSilo protocol client. It has no Paymenter entry point,
service, billing or management integration and is not installable or usable yet.

Construct `RegistrarClient` with an explicit `Environment` and `Transport` (production:
`CurlTransport`); call `execute` with a typed read-only `RegistrarRequest` and explicit `RequestContext`.
No default environment/context, arbitrary command/URL, automatic retry or billing decision exists.
Use `Outcome` to preserve success-with-fallback and unknown results; `300` is an API acknowledgement,
not registry completion. All request mappings and public evidence are in the [contract](../docs/design/api-contract.md).

Request parameter access is sensitive wire data. Never log it; transfer auth and contacts must not
be persisted unencrypted by future integrations. Injected transports are trusted credential handlers: they must not log keys/URLs and must annotate sensitive parameters. Result serialization/debug output is summary-only.
`value()` accesses sanitized in-memory reply fields, which still contain private account/cost data
and must stay server-side. Amounts are canonical two-decimal strings, preserved from JSON lexemes without float conversion.
They are protocol evidence; no price-ceiling or accounting/admission guarantee is provided.

`PurchaseMapping` holds register/renew/transfer wire mappings and cannot be passed to the client or
transport. `ResponseParser` classifies offline purchase evidence without dispatching it.
Syntax normalization cannot establish non-premium eligibility. Before executing registration or
transfer, future admission must prove an ordinary name, current price/term, ownership, funding,
account binding and no prior uncertain send. These gates are not implemented here. Unmapped TLDs,
IDN and premium/EAP offerings are excluded. Renewal builders are draft parameter mappings only;
all purchase dispatch awaits a separate admitted write workflow; renewal also needs its flag and
restoration questions resolved. Availability premium=null means unknown, not ordinary eligibility.

Run the [offline tests](../docs/testing.md). Provider/sandbox acceptance and Paymenter 1.5.9
compatibility remain unverified. The distributable license is [MIT](LICENSE).

## What you sell

Domains → What you sell lists the TLDs the Enom account offers, with one GetTLDList call. Terms and prices are read
only for TLDs staff turn on. To hide TLDs we never want, list them in config `domains.hidden_endings`
(for example `['xxx','zip']`). A hidden TLD stops selling, including one already on sale. It stays on the list as
Renewals only, so names already held keep renewing.

## Public suffix list

Domains → Public suffix list shows saved local status. Viewing requires domain-view and
`admin.domains.watch.view`; updating or changing the source also requires
`admin.domains.watch.manage`. Both new permissions default to denied.
The existing runtime scheduler checks weekly, including when no registrar account exists.
Manual and scheduled updates share one rolling 24-hour network allowance.
The extension-wide native setting `domains_psl_source_url` is editable on that page; its default
is `https://publicsuffix.org/list/public_suffix_list.dat`. Only credential-free HTTPS URLs
without query/fragment on public endpoints are allowed. Redirects and retries are refused.
The Public Suffix List data is licensed under [MPL-2.0](https://publicsuffix.org/list/) and
comes from publicsuffix.org; no downloaded list or runtime package is bundled.

Validation requires 100 KiB–1 MiB, at least 1,000 ICANN and 100 PRIVATE rules, ordered section
markers, valid UTF-8/IDNA rules, and com/co.uk/github.io sentinels. These deliberately broad
bounds accommodate the approximately 230 KB / 10,000-rule source while refusing truncated
responses. More than 5% of either section’s previous rules disappearing rejects the update,
even if added rules hide the net loss. Only the current and one previous compressed copy remain.
No-change 304 responses update checked time only; successful 200 downloads (including identical
bytes) revalidate freshness. The freshness limit is defined once in `Services/PublicSuffixList::MAX_AGE_DAYS`.
New discovery is held before the first validated download and strictly after that limit. The resolver derives names only; discovery is not enabled. The native intl extension is required to parse IDNs.
The HTTP connect/transfer limits are 10/30 seconds. Native DNS resolution is checked before the
HTTP exchange and deducted from its remaining budget; the system resolver itself has no PHP timeout.

Once the list passes the freshness limit, requests omit conditional validators so an unchanged
200 download can revalidate freshness. Stored baselines ignore current acceptance floors;
a damaged or no-longer-parseable baseline is reported separately as a saved-copy failure.
A damaged stored copy still requires restoring a verified compatible backup; do not remove
snapshots to bypass validation. For legitimate removals or stored rules rejected by a newer ICU,
**Public suffix list → Accept this list** shows the pending hash/source/date, saved baseline,
section counts and up to 20 removed-rule samples per section. Watch view/manage permission,
the typed short hash and a reason are required. Acceptance binds the exact pending bytes and
baseline, retains encrypted audit, and never bypasses current parsing, size, marker or sentinel
checks. Historical raw identities explain ICU differences only; they never resolve hostnames.
Acceptance keeps the original download age. Unparseable saved data immediately holds discovery
and forces an unconditional download. A normal new validated revision clears stale candidates.

Domain watch storage is dormant: [retained identities and disabled mappings](../docs/watch-storage.md). Discovery and the mapping preview/enable screen remain follow-on work.
