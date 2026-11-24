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

## Contact support

"Contact support" links the ticket form. With tickets off, set config `domains.support_url` to an `http:`, `https:`, `mailto:`
or `tel:` link to use that instead. With neither, Paymenter offers nothing safe to fall back on, so the Registrars page
System checks show it as Missing with this next step, and sentences that point at support drop that part.
Customers see it only after login.
