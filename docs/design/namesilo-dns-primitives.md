# Dormant NameSilo DNS primitives

`NameSilo\Dns` and `Api\DnsMapping` are pure adapters. No caller, capability, form,
request, scheduler or transport was changed. DNS editing remains unsupported.
`DnsMapping` is deliberately outside `RegistrarRequest`; existing clients and
transports refuse it. A later sequence integration must authorize each write.

`Dns::inventory(body, domain)` returns `state` (`complete`, `partial`, `unknown`)
and `records`. Bind the supplied body to the requested account/domain before use.
Only an explicit `resource_record: []` proves an empty inventory. Missing or
unusable lists are unknown. Malformed siblings, duplicate IDs, unrecognized fields
or metadata, and the 50-row bound prevent completeness. Valid rows survive partial
lists. Duplicate JSON object keys invalidate the response instead of overwriting
facts silently. Only complete inventory can be a sequence preimage.

Rows use shared `name`, `type`, `address`, `mx_pref`, plus `record_id` and `ttl`.
Owners are relative (`@` for apex); CNAME/MX targets have a root dot. TXT bytes and
CAA wire text are retained up to 2048 bytes without control bytes: a value with a C0
byte (tab, newline) or DEL is refused, so its row prevents completeness. CAA requires a flag/tag/value triplet, with an unsigned
8-bit flag, alphanumeric tag and nonempty value (which can contain colons); malformed
CAA prevents completeness while valid siblings survive. SRV retains its wire value and distance, with parsed
weight/port/target and `priority_proven: false`; distance is never mapped to
priority. CAA/SRV builders remain unavailable. Unknown types cannot produce a
complete inventory. These server-side facts are not public DTOs or log content.

`DnsMapping::listRecords/add/update/delete` build documented per-record commands.
Standard builders accept A/AAAA/CNAME/MX/TXT, require explicit TTL, and send distance
only for MX. Shared unrooted owners are always relative, including names ending
in the zone name. A root dot explicitly marks in-zone absolute builder input.
Provider inventory owners are absolute with or without a root dot and have the
zone suffix removed exactly once. Apex maps to empty
`rrhost`. Updates require an observed ID, preserve type and omit undocumented
`rrtype`. A type change needs separate sequence deltas. IDs are opaque alphanumeric
strings, TTL is an unsigned 31-bit value, and MX preference is unsigned 16-bit.
These are local bounds, not proven provider acceptance limits.

`Dns::acknowledgement` parses original mutation responses into `RegistrarResult`.
Success is an acknowledgement, never current-state confirmation. Add/update must
return an ID, and update must return a different ID. Nonstandard, malformed or
lost replies remain unknown; no result authorizes replay. Delete has no new ID.
Independent inventory retains replacement IDs and TTL and preserves duplicate
content under distinct IDs. The later sequence must establish unique readback,
recheck ownership/configuration/preimage, and pause ambiguous steps without
rollback or replay. Explicit empty inventory can prove final-record deletion;
missing inventory cannot.

Evidence: synthetic `namesilo-dns-{inventory,mapping,readback}-test.php` fixtures,
based on saved dnsList/Add/Update/DeleteRecord schemas. No provider acceptance,
installed proof, durable sequence or form integration is claimed.
