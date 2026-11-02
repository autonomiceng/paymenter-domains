# Enom TEST child-host reply shapes

These synthetic fixtures preserve the XML shape observed in the #375 R2 Enom TEST proof on 2026-11-02 UTC. They are sanitized derivatives, not byte-for-byte captures. The host is `ns-glue.example.test`; host, registry, server and tracking identifiers use reserved example names. IPs use documentation ranges, and timestamps are synthetic. No provider calls run in these tests.

| Fixture | Synthetic address |
| --- | --- |
| `enom-checkns-created.xml` | `192.0.2.1` |
| `enom-checkns-updated.xml` | `198.51.100.1` |
| `enom-checkns-absent.xml` | None |

The observed success field is `CheckNsStatus.name`, with `ipaddress`, `NsCheckSuccess=1`, `ErrCount=0` and `RRPCode=200`. Exact 545 absence keeps its original codes and error text. Element names, casing, order and nesting are preserved in all three fixtures.

The parser test uses these sanitized fixtures unchanged. The shared management transport uses the created fixture and substitutes only the requested synthetic hostname and fixture address; absence uses its sanitized fixture unchanged. Preserve the observed wire structure when changing test values.
