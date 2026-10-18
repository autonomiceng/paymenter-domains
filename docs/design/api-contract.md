# NameSilo API contract

Read 2026-10-17 (Pacific). Public documentation only; no registrar requests or account access.
This is a design contract, not provider acceptance. See [parity and gates](parity.md).

## Wire and outcome rules

[API reference](https://www.namesilo.com/api-reference) specifies HTTPS GET, including mutations,
`version=1`, `type=json` and a query `key`. Each endpoint below is `/{route}/{command}`.
Every row uses route **I/B**: caller-declared interactive human work → `/api`; automated,
repetitive or background work → `/apibatch`. No inference from command or HTTP method.
The [batch policy](https://www.namesilo.com/support/v2/articles/account-options/api-automated-batch)
requires that distinction; queues should declare background pending support clarification.

Live origin is `https://www.namesilo.com`. The reference requests support-issued sandbox credentials;
its landing page does not publish a sandbox origin. NameSilo's
[official module](https://github.com/namesilo/whmcs/blob/master/namesilo.php) defines
`https://sandbox.namesilo.com`. Use an explicit environment enum, never arbitrary caller URLs.
Confirm that origin and batch support with support before acceptance.

JSON is an object with `request: {operation, ip}` and `reply: {code, detail, ...}`;
there is no outer `namesilo` property (that is the XML root). Tables list additional **reply**
fields. `Ack` means only the common envelope. Code 300 acknowledges an API operation; it does
not establish transfer completion, asynchronous registry contact updates or financial finality.
Documented examples mix strings and numbers and contain typos/invalid JSON; fixtures must be
synthetic valid JSON, not captured accounts or mechanically copied examples. Empty/singleton
collection shapes and timestamp zones need acceptance evidence. Do not derive money using floats.

Transport: verify peer and hostname, HTTPS only, no redirects/proxy inheritance/retries,
8-second connect/25-second total timeout, 1 MiB response cap (including injected transports).
Larger catalogues/order histories fail unknown; do not silently truncate. No full URLs, query
strings, provider messages, account bodies or keys in diagnostic output or stored result summaries.
Only explicitly needed reply fields are accessible in memory; unknown results discard the body.

## Reply codes

Source: [API reference, Response Codes tab](https://www.namesilo.com/api-reference).
This table records provider meanings; command pages generally show only 300. The per-operation
policy below determines which refusals are authoritative for each mapped command.
These are candidate local classifications, applied only to relevant operations; an inapplicable code is unknown, not a definitive refusal. They are not a provider guarantee of safe retry. No outcome automatically
retries/refunds. In particular, already-existing/in-flight requests require reconciliation.

| Code | Documented meaning (paraphrased) | Local disposition |
| --- | --- | --- |
| 101 | HTTPS required | rejected |
| 102, 103 | Missing / invalid version | rejected |
| 104, 105 | Missing / invalid format | rejected |
| 106, 107 | Missing / invalid operation | rejected |
| 108 | Required parameter missing | rejected |
| 109, 110 | Missing / invalid key | rejected |
| 111, 112, 113 | Invalid user / subaccount / source IP | rejected |
| 114 | Domain syntax invalid | rejected |
| 115 | Registry not answering | unknown |
| 116 | Invalid sandbox account | rejected |
| 117, 118 | Card profile invalid / unverified | rejected |
| 119, 120 | Insufficient funds / key must use GET | rejected |
| 200 | Domain inactive or outside account | rejected |
| 201, 210 | Internal / general error | unknown |
| 250–253, 255–256 | Requested auto-renew, lock or privacy state already set; no change | rejected acknowledgement; read back desired state |
| 254 | Nameserver change refused | rejected |
| 261 | Domain processing error | unknown |
| 262 | Domain already active here | unknown; could be prior purchase |
| 263, 264 | Term missing/invalid / renewal term refused | rejected |
| 265, 266, 267 | Transfer unavailable / no transfer / name or TLD invalid | rejected |
| 268 | Prior nameserver task unfinished | unknown |
| 280 | DNS modification error | unknown |
| 300 | API success | success |
| 301 | Registration succeeded using provider nameservers | success-with-fallback |
| 302 | Order succeeded using account contact defaults | success-with-fallback |
| 400 | Earlier API request processing; docs suggest resubmission | unknown; never auto-resubmit |
| Other, malformed envelope, timeout, HTTP error | No authoritative outcome | unknown |

301/302 on a purchase with matching domain and valid amount mean a purchase with a discrepancy.
Retain sanitized domain/charge evidence, block original-write replay and automatic refund, and
arrange separately authorized correction/readback. Unexpected read-command fallbacks or mismatched
purchase evidence stay unknown; that also forbids replay/refund. A rejection containing purchase
amount/order evidence is contradictory and must remain unknown. Provider detail text never controls
classification. API success is separate from business completion and is never retry authority.

Current refusal policy: common validation codes 101–114 and 116–120 apply to all mapped operations;
getDomainInfo additionally accepts 200; availability 267; register 263/267; transfer 263/265–267;
draft renew 263/264. Every other non-success code stays unknown. State-toggle acknowledgements
250–256 do not apply to current read/purchase mappings. Refusals carry request, credential/account,
funds or domain-state scope so future processing can hold account-wide problems.

`RegistrarRequest` and transport/client execution accept reads only. `PurchaseMapping` exposes
register/renew/transfer parameters with no dispatch path. `ResponseParser` can classify synthetic
purchase evidence independently, without sending it. A later authorized write slice must introduce
funding/ownership/premium/cost/prior-outcome admission before adding a purchase transport path.
Availability collections normalize to lists and must cover exactly the requested names once;
missing premium evidence is explicit null, never ordinary-name proof. Monetary JSON lexemes are
preserved before float conversion and returned as canonical two-decimal strings; numeric IDs are
normalized to digit strings. Unknown three-digit codes remain available for diagnosis.

## Search, lifecycle and evidence

All rows: **I/B**, common JSON envelope and code table above. Optional parameters marked `?`.

| Command / public source | Parameters | Additional reply shape / decision |
| --- | --- | --- |
| [checkRegisterAvailability](https://www.namesilo.com/api-reference/pages?uid=domains/check-register-availability) | `domains` comma list, maximum 200 | `available:[{domain,price,premium,duration}]`, `unavailable:{domain}`, `invalid:{domain}` in example. Exclude premium; price/duration are evidence, not a spend cap. |
| [checkTransferAvailability](https://www.namesilo.com/api-reference/pages?uid=domains/check-transfer-availability) | `domains` comma list, maximum 200 | `available:[{domain,price,premium?}]`, `unavailable:[{domain,reason}]`. Missing premium flag is not positive ordinary-name proof. |
| [getPrices](https://www.namesilo.com/api-reference/pages?uid=account/get-prices) | `retail_prices?` (presence requests retail), `registration_domains?` | TLD-keyed `{registration,transfer,renew}`. Omit retail flag for account discounts; no exact-term/promotion guarantee. |
| [registerDomain](https://www.namesilo.com/api-reference/pages?uid=domains/register-domain) | `domain`, `years` 1–10; `private?`, `auto_renew?`, `payment_id?`, `portfolio?`, `coupon?`, `ns1`–`ns13?`, contact fields or `contact_id?` | `{message,domain,order_amount}`; example has no order identifier. Explicit `private=0/1`, `auto_renew=0`; no card profile. At least two valid registry hosts or 301 fallback. Reject invalid contacts through Reseller Manager setting before live activation. |
| [renewDomain](https://www.namesilo.com/api-reference/pages?uid=domains/renew-domain) | `domain`, `years` 1–10; `payment_id?`, `coupon?` | `{message,domain,order_amount}`. Can restore during redemption unless disabled in **API Manager**. No documented per-call restore ceiling/opt-out, privacy or auto-renew parameters. See renewal gate below. |
| [transferDomain](https://www.namesilo.com/api-reference/pages?uid=domains/transfer-domain) | `domain`; `auth?`, `private?`, `auto_renew?`, `payment_id?`, `portfolio?`, `coupon?`, `ns1`–`ns13?`, contact fields or `contact_id?` | `{message,domain,order_amount}`. Explicit flags as registration. Encode auth as `base64:` plus base64 then URL encode. Initial acknowledgement is not completed transfer; no chosen `years` parameter documented. |
| [checkTransferStatus](https://www.namesilo.com/api-reference/pages?uid=transfers/check-transfer-status) | `domain` | `{date,status,message}`; latest status only. Example `Pending at Registry`; exhaustive status mapping not established. Unknown strings must not imply completion/rejection. |
| [transferUpdateChangeEPPCode](https://www.namesilo.com/api-reference/pages?uid=transfers/transfer-update-change-epp-code) | `domain`, `auth` | Ack; same base64 option. Explicit recovery, never purchase replay. |
| [transferUpdateResendAdminEmail](https://www.namesilo.com/api-reference/pages?uid=transfers/transfer-update-resend-admin-email) | `domain` | Ack, but response sample incorrectly names ChangeEPPCode. Confirm operation echo; staff-only until proved. |
| [transferUpdateResubmitToRegistry](https://www.namesilo.com/api-reference/pages?uid=transfers/transfer-update-resubmit-registry) | `domain` | Ack; staff-only reconciliation decision, no automatic resubmission. |
| [retrieveAuthCode](https://www.namesilo.com/api-reference/pages?uid=transfers/retrieve-auth-code) | `domain` | Ack; **emails administrative contact**, does not return an auth code. Acceptance is not delivery. |
| [getDomainInfo](https://www.namesilo.com/api-reference/pages?uid=domains/get-domain-info) | `domain` | `{created,expires,status,locked,private,auto_renew,traffic_type,email_verification_required,portfolio,forward_url,forward_type,nameservers:[{nameserver,position}],contact_ids:{registrant,administrative,technical,billing}}`. Flags are Yes/No; date-only expiry. |
| [listDomains](https://www.namesilo.com/api-reference/pages?uid=domains/list-domains) | `portfolio?`, `pageSize?`, `page?`, `withBid?`, `skipExpired?`, `expiredGrace?` | `{domains:[{domain,created,expires,maxBid?}],pager:{page,pageSize,total}}`. Account inventory is not permission to adopt domains. |
| [getAccountBalance](https://www.namesilo.com/api-reference/pages?uid=account/get-account-balance) | none | `{balance}`. Read-only evidence, no funding command in this slice. |
| [listOrders](https://www.namesilo.com/api-reference/pages?uid=account/list-orders) | `date_from?`, `date_to?`: date or date-time | `{order:[{order_number,order_date,method,total}]}`. Complete history advertised; timezone, pagination, consistency and lag unknown. |
| [orderDetails](https://www.namesilo.com/api-reference/pages?uid=account/order-details) | `order_number` | `{order_number,order_date,method,total,order_details:[{description,years_qty,price,subtotal,status,credited_date?,credited_amount?}]}`. Example statuses Processed/Credited; description embeds domain/action rather than structured fields. No exhaustive status vocabulary. |

The linked register/transfer pages explicitly define value-based `private` and `auto_renew`:
0 disables and 1 enables; omission disables privacy but enables auto-renew. This differs from
presence-based `retail_prices`. Parameters alone are not the authoritative control: a future write
workflow must read getDomainInfo, verify `auto_renew=No` and expected privacy, and removeAutoRenewal
before accepting Paymenter renewal authority.

**Renewal gate:** the task requires explicit `auto_renew=0` and privacy on all three purchase
builders. Preserve those fields in a draft renewal mapping, but provide no purchase dispatch
until support confirms their behavior or an approved readback/control sequence replaces that
mapping. Undocumented parameters cannot enforce policy. Disable restores in API Manager and
prove normal/grace eligibility independently. Renewal builder output alone is not safe fulfillment.

No documented client idempotency token or atomic maximum-price parameter was found. Future
execution must admit one operation, retain pre-send order snapshots, correlate account/domain/action/
term/time/charge evidence and hold ambiguous matches. Domain existence or an expiry change alone
cannot prove which operation charged; a timeout never authorizes a second purchase.

## Contacts and .us

All rows: **I/B**, common envelope/code table. Contact fields are URL encoded once.

| Command / public source | Parameters | Additional reply / decision |
| --- | --- | --- |
| [contactAdd](https://www.namesilo.com/api-reference/pages?uid=contact/contact-add) | Required `fn(32),ln(32),ad(128),cy(64),st(64),zp(16),ct(4),em(128),ph(32)`; optional `nn(24),cp(64),ad2(128),fx(32),usnc,usap,calf,caln,caag,cawd,eucs` | `{contact_id}`. US/CA use state abbreviations and 10-digit phones; omit country dialing prefix. Profile ID must belong to correct customer. |
| [contactUpdate](https://www.namesilo.com/api-reference/pages?uid=contact/contact-update) | `contact_id` plus same required identity fields; same optional fields | Ack. Updates **all domains sharing that profile**; avoid cross-customer profiles. |
| [contactList](https://www.namesilo.com/api-reference/pages?uid=contact/contact-list) | `contact_id?`, `offset?`; maximum 1000 per request | `{contact:[{contact_id,default_profile,nickname,company,first_name,last_name,address,address2,city,state,zip,country,email,phone,fax,usnc?,usap?,calf?,caln?,caag?,cawd?,eucs?}]}`. Docs elsewhere call it getAccountContacts; use documented contactList, confirm alias separately. |
| [contactDomainAssociate](https://www.namesilo.com/api-reference/pages?uid=contact/contact-domain-associate) | `domain`; at least one of `registrant,administrative,billing,technical` as contact IDs | Ack **queues asynchronous update which may later fail**. Readback must resolve completion. |

Registration/transfer accept the same inline identity fields or `contact_id` for all roles.
Incomplete or invalid identity may silently use account defaults (302); validate first, enable
Reseller Manager rejection and prove behavior. `.ca`/`.eu` need additional profile requirements;
disable until mapped and accepted.

[.us abbreviations](https://www.namesilo.com/popups/us_abbreviations.php): `usnc` is C11 (citizen),
C12 (permanent resident), C21 (US organization), C31 (foreign business operating in US), or C32
(foreign entity with US office). `usap` is P1 (commercial), P2 (nonprofit), P3 (personal), P4
(education), or P5 (government). Collect explicit eligibility, never invent defaults. Creation,
transfer and profile updates document these fields; verify whether inline nexus is honored alongside contact_id or a stored profile must carry it at
purchase, and confirm privacy eligibility and any extra foreign-entity country field before offering.

## Nameservers, flags, DNS and DS

All rows: **I/B**, common envelope/code table. Ack mutations require later readback.

| Command / public source | Parameters | Additional reply / gaps |
| --- | --- | --- |
| [changeNameServers](https://www.namesilo.com/api-reference/pages?uid=nameserver/change-nameserver) | `domain` (comma list ≤200), `ns1,ns2`, optional through `ns13` | Ack. Prefer one domain per mutation. |
| [listRegisteredNameServers](https://www.namesilo.com/api-reference/pages?uid=nameserver/list-registered-nameservers) | Page omits parameter list; domain scope implied | `{hosts:[{host,ip}]}`; example repeats identical `ip` keys, losing multi-address data in ordinary JSON parsers. Confirm real array shape. |
| [addRegisteredNameServer](https://www.namesilo.com/api-reference/pages?uid=nameserver/add-registered-nameserver) | `new_host` relative label, `ip1`, optional `ip2`–`ip13`; domain omitted in prose | Ack. Domain scoping needs support/example confirmation. IP examples are IPv4; IPv6 not explicitly documented. |
| [modifyRegisteredNameServer](https://www.namesilo.com/api-reference/pages?uid=nameserver/modify-registered-nameserver) | `current_host,new_host,ip1`, optional `ip2`–`ip13`; relative hosts | Ack. Same scope/address gaps; confirm replacement vs merge. |
| [deleteRegisteredNameServer](https://www.namesilo.com/api-reference/pages?uid=nameserver/delete-registered-nameserver) | `current_host` relative; domain omitted in prose | Ack. Registry refuses hosts still in use; see [management guide](https://www.namesilo.com/support/v2/articles/domain-manager/manage-name-servers). |
| [domainLock](https://www.namesilo.com/api-reference/pages?uid=domains/domain-lock) / [domainUnlock](https://www.namesilo.com/api-reference/pages?uid=domains/domain-unlock) | `domain` | Ack; 252/253 already-set responses require state readback. |
| [addPrivacy](https://www.namesilo.com/api-reference/pages?uid=privacy/add-privacy) / [removePrivacy](https://www.namesilo.com/api-reference/pages?uid=privacy/remove-privacy) | `domain` | Ack; 255/256 already-set responses. Eligibility is TLD-dependent. |
| [addAutoRenewal](https://www.namesilo.com/api-reference/pages?uid=domains/add-auto-renewal) / [removeAutoRenewal](https://www.namesilo.com/api-reference/pages?uid=domains/remove-auto-renewal) | `domain` | Ack; 250/251 already-set responses. Only removal belongs in normal Paymenter-controlled operation. |
| [dnsListRecords](https://www.namesilo.com/api-reference/pages?uid=dns/dns-list-records) | `domain` | `{resource_record:[{record_id,type,host,value,ttl,distance}]}`. Listing type prose lags add/update support. |
| [dnsAddRecord](https://www.namesilo.com/api-reference/pages?uid=dns/dns-add-record) | `domain,rrtype,rrhost,rrvalue`; `rrdistance?`, `rrttl?` | `{record_id}`. A/AAAA/CNAME/MX/TXT/SRV/CAA; relative host, SRV value `weight:port:target`, CAA `flag:tag:value`. |
| [dnsUpdateRecord](https://www.namesilo.com/api-reference/pages?uid=dns/dns-update-record) | `domain,rrid,rrhost,rrvalue`; `rrdistance?`, `rrttl?` | `{record_id}` is **new** after update; type change not documented. |
| [dnsDeleteRecord](https://www.namesilo.com/api-reference/pages?uid=dns/dns-delete-record) | `domain,rrid` | Ack; deleting forwarding records also removes forwarding. |
| [dnsSecListRecords](https://www.namesilo.com/api-reference/pages?uid=dns/dns-seclist-records) | `domain` | `{ds_record:[{digest,digest_type,algorithm,key_tag}]}`. |
| [dnsSecAddRecord](https://www.namesilo.com/api-reference/pages?uid=dns/dns-secadd-records) / [dnsSecDeleteRecord](https://www.namesilo.com/api-reference/pages?uid=dns/dns-secdelete-record) | `domain,digest,keyTag,digestType,alg` | Ack. Deletion identifies DS tuple, not record ID. Use per-registry accepted algorithm/digest set; [DS guide](https://www.namesilo.com/support/v2/articles/domain-manager/ds-records) says some TLDs instead need public-key input, not documented here. |

DNS add/update say `rrdistance` is MX-only (default 10), leaving SRV **priority** mapping unclear;
weight/port/target are documented. TTL default is 7207; send an explicit validated TTL. Preserve
TXT bytes and verify readback. AAAA DNS support does not establish IPv6 glue support. Do not offer
SRV editing or IPv6 glue until those gaps are resolved.
