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
| parity | Paid/unpaid readiness, cancellation, suspended service, permissions, and applicable financial, contact and input faults |
| reasons-enabled/disabled | Every mapped reason, every RenewalCode, null and future codes. Compares guidance, notice, button state and writer outcome, including byte-preserving refusals |

Each reason entrypoint restores the same held request before each code. It checks the
future-code page, writer refusal and zero registrar writes for its deployment setting.

The state entrypoints retain complimentary renewal funding and fund permission, mixed
invoices, missing contacts and registry support, contact recovery, peer work, owner,
readmission limit, authority and transfer cutoff. They also exercise the retained
unsent call cap in both modes and missing, invalid or expired transfer inputs.
Each operation runs the faults that apply to it.

All state entrypoints retain read-only offer SQL checks, financial facts/reason visibility,
actual Livewire action visibility/enabled state, actual writer outcomes and immutable refusals.
