# Staff release parity guard map

The register, transfer and renewal entrypoints share `Support/staff-release-states.php`
and `Support/staff-release-reasons.php`. Each scenario restores committed SQLite rows,
including audit and review tickets, without putting provider calls inside a transaction.
Paid, unpaid and missing-contact variants are each seeded once per file through real
native checkout and payment. Restores retain their original clock and actor; each fault
then exercises its own real hold, claim, release or complimentary-cycle writer.
No reason rows or writer assertions were removed.

| Entry suffix (after staff[-transfer/-renewal]-release) | Retained guards |
| --- | --- |
| parity | Paid/unpaid readiness; renewal complimentary funding and fund permission |
| order-parity | Cancellation, suspended service, mixed invoice, manage permission |
| contacts-parity | Missing contacts/registry support, recovery, peer work, owner, readmission limit, authority, transfer cutoff |
| inputs-parity | Retained unsent call-cap proof on/off; transfer missing/invalid code/payment timestamp and expired deadline |
| reasons-[acquisition-/renewal-]enabled/disabled | Every mapped reason plus every RenewalCode, null and future code, in both deployment modes; guidance, notice/action/writer equality, button enabled state, accepted queued state or transfer contact-review hold, byte-preserving refusals |

The unsuffixed reason group contains general codes; acquisition contains registration,
transfer, contact and TEST-send codes; renewal contains renewal/provider codes. The
fallback puts newly mapped codes in general, so additions remain covered automatically.
The required offered-code lists are checked in the group that owns each code. Each reason
entrypoint also checks a real future-code page, writer refusal and zero registrar writes.
Renewal has no acquisition input/contact state entrypoints: those rows were already
excluded by the original matrix. Register/transfer retain their applicable state rows.

All state entrypoints retain read-only offer SQL checks, financial facts/reason visibility,
actual Livewire action visibility/enabled state, actual writer outcomes and immutable refusals.
