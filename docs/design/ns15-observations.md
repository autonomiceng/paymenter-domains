# NS15 observation handoff

Backend only, on C0 `e1631bd4`. No SQL migrations, backfill, runtime activation or provider calls.
Old encrypted rows retain their legacy reader rules; only bounded refresh through the injected new reader
produces new evidence. The existing OwnerPorts fallback keeps its legacy producer behavior until C1 wiring.

## C1 wiring

Select the new provider `ObservationReader` helpers through the C0 account-bound runtime port.
Pass `DomainObservation`'s optional trailing `observationReader` closure a binding-to-reader resolver.
Keep the existing processing gate, read limiter, original holding account, deadline, fair cursor and
management-sequence guards. No provider Management/DnsManagement or factory files changed here.
New helpers implement the frozen read port. Enom wraps existing identity/record discovery; NameSilo
uses its existing owned-domain proof and canonical full DNS/glue parsers. Neither adds mutation grants.

The producer writes `format=1`, the frozen capability revision and all four `expected_families`.
Read support and edit/mutation eligibility are independent. Unsupported families stay unknown.
NameSilo DNS includes opaque CAA/SRV, provider IDs and TTL; its separate SRV editor remains unsupported.
DS reads remain unsupported for NameSilo pending D. Enom retains its existing DS read behavior.
Malformed siblings retain valid partial rows but withdraw complete-list authority. New complete
lists replace old evidence; primary read failures preserve the exact inventory and timestamps.
Enom glue remains a bounded partial refresh, not an enumerable full inventory.

The helpers also return `settings` for nameservers, contacts and flags. NameSilo resolves at most four
exact contact IDs, deduplicating shared IDs; missing roles stay partial/unknown. Both helpers stop at
first failed wire. No raw account inventory or contact profile adoption is introduced. Quota/deadline
and binding refusal use the existing request context. Local quota refusal still defers the turn.

## UI data

`ObservationProjection::recordStatus(domain, now)` supplies registrar-only evidence:

- Per-family expectation (supported/unsupported/unknown), complete/partial/unknown state, current
  rows/facts, attempt time, and separate last-good values/time. Partial data is for display only.
- Settings states for nameservers, contacts and flags; `stale` at one day or primary failure;
  identity-bound resume and failed-family metadata; latest overall complete time.
- `OwnerRecords` uses only complete sequenced sections for editable inventories. NameSilo's existing
  `DnsForm`/`DnsSequence` keeps CAA/SRV read-only and preserves their exact bytes while other records change.

This serves the approved NS15 customer/staff/monitoring/history/settings sheets in the Demo C pack.
UI must keep public-DNS findings/timestamps separate from registrar observations. Public DNS cannot
confirm registrar writes, renewals, billing or ownership. No watch/public tables or views change here.
Last-good data never grants edit authority; contact facts belong only in the authorized domain view.

## Integration evidence still required

The paired native regressions extend the existing observation files, so C1 needs no test registration.
The existing standalone C0 reader regression now also covers provider canonicalization and bounded
helper reads. Parent owns independent review, fixed-B rebase, installed and MariaDB acceptance.

After D's fail-closed read contract, C1 must wire NameSilo DS discovery and run paired complete-empty,
partial/malformed, DS-Off/read-supported and failed-family resume tests. Do not infer read support
from mutation eligibility or a populated registry map. Final integrated completeness tests wait for D.
Existing account fairness, seller changes, primary-failure retention and management-sequence tests
must run once the inherited frozen-base fixture failures are repaired; see the lane report for receipts.
