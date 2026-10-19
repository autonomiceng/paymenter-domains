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
bytes) revalidate freshness. Warning starts strictly after 30 days; new discovery is held
before the first validated download and strictly after 90 days. No discovery or resolver is
shipped here. The native intl extension is required to parse IDNs.
The HTTP connect/transfer limits are 10/30 seconds. Native DNS resolution is checked before the
HTTP exchange and deducted from its remaining budget; the system resolver itself has no PHP timeout.

Once the list is more than 30 days old, requests omit conditional validators so an unchanged
200 download can revalidate freshness. Stored baselines ignore current acceptance floors;
a damaged or no-longer-parseable baseline is reported separately as a saved-copy failure.
Restore a verified compatible database backup before retrying a saved-copy failure; do not
remove snapshots to bypass the loss guard. A legitimate removal above 5% remains held.
Before discovery is enabled, a separately designed audited baseline-replacement procedure
is required for that case and for stored rules rejected by a newer ICU version; this slice intentionally provides no loss-check override.
