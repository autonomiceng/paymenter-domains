# NameSilo DS evidence

Reviewed 2026-11-11 for parity plan row 14. No real suffix qualifies for
`DsRegistryPolicy::DEPLOYMENT_FACTS`. The existing default refusal remains necessary.
Public documentation retrieval made no authenticated requests or registrar API calls.

## Provider contract

The [add API documentation](https://www.namesilo.com/api-reference/pages?uid=dns/dns-secadd-records)
was retrieved successfully. It specifies `domain`, `digest`, `keyTag`, `digestType`
and `alg`. Both numeric-value references point to the
[accepted-values popup](https://www.namesilo.com/popups/dnssec_records.php), which
returned HTTP 403 on this review. No accepted values were obtained from that page.
The saved delete contract identifies the same full tuple, without a record ID.

The [provider help](https://www.namesilo.com/support/v2/articles/domain-manager/ds-records)
describes two input modes, DS and public key, but does not associate either mode
with specific suffixes. Availability also varies by domain. Its broad
[compatibility statement](https://www.namesilo.com/blog/en/privacy-security/dnssec-gap-site-security)
for `.com`, `.net` and `.org` concerns automatic DNSSEC with its nameservers;
it does not establish the manual DS API contract for those suffixes.

## Registry evidence

| Suffix | Primary source and established fact | Missing evidence |
| --- | --- | --- |
| `.com` | [Verisign COM practice statement v1.9](https://www.verisign.com/assets/COM_VerisignDNSSECPracticeStatement_v1.9.pdf), sections 3.2–3.5, supports child DS delegation. | Child DS algorithm/digest acceptance and NameSilo per-suffix input/API support. The registry's own signing algorithms are not child DS policy. |
| `.net` | [Verisign NET practice statement v2.0](https://www.verisign.com/assets/NET_VerisignDNSSECPracticeStatement_v2.0.pdf), sections 3.2–3.5, supports child DS delegation. | Child DS algorithm/digest acceptance and NameSilo per-suffix input/API support. |
| `.org` | [PIR-hosted Identity Digital practice statement](https://pir.org/wp-content/uploads/2025/03/ID_DNSSEC.4.1.pdf), identified inside as v2.00, describes registrar submission of child DS data. | Child DS algorithm/digest acceptance and NameSilo per-suffix input/API support. Its signing section misidentifies Ed25519 as algorithm 13; do not derive policy from that section. |
| `.uk` | [Nominet registrar documentation](https://registrars.nominet.uk/uk-namespace/registration-and-domain-management/dnssec/dnssec-for-registrars/) explicitly accepts DS input with algorithms 5, 7, 8, 10, 12, 13, 14, 15, 16 and digest types 1, 2, 4. | NameSilo per-suffix input/API support and accepted algorithm/digest combinations. This registry-only list grants no NameSilo capability or rules for other UK suffixes. |

The COM, NET and ORG findings use previously saved source reviews dated
2026-11-03. Nominet and the provider pages were checked on 2026-11-11.
The [Verisign registry mapping](https://origin.www1.verisign.com/assets/epp-sdk/verisign_epp-extension_registry_v01.html)
defines how registry-specific algorithm and digest values can be reported;
its example response is not a COM or NET production acceptance list.
[IANA assignments](https://www.iana.org/assignments/dns-sec-alg-numbers/)
identify numeric algorithms, not registrar or registry acceptance.

## Completion requirements

Before adding a suffix, obtain attributable provider and registry sources that
establish its DS-versus-key input mode and accepted algorithm/digest pairs.
Record source versions, review and expiry instants, and `expiry_policy=refuse`
in the existing versioned map. Keep unknown and key-only suffixes closed;
never substitute Enom's accepted list or multiply independent sets into pairs.

Keep the existing `domains_operations` sequence and frozen policy/preimage;
`ObservationRecords` and `RecordInventory` retain family completeness. No new
table, catalogue writer or audit writer is needed. The existing adapter already
requires current policy before sending and independent exact-tuple readback,
including explicit empty inventory after confirmed final deletion.

The runtime owner combines DNS and DS adapters; forms and provider acceptance
remain integration work. OTE and Live mutation proof require separate authority,
an owned test domain and an agreed DNSSEC change. No funds or consent are needed
to finish public-source research. If public contracts stay unavailable, an
authorized support request or portal capture is the next evidence source.
