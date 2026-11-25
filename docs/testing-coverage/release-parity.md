# Staff release parity guard map

The register, transfer and renewal parity entrypoints share `Support/staff-release-states.php`.
Each scenario restores committed SQLite rows,
including audit and review tickets, without putting provider calls inside a transaction.
Paid, unpaid and missing-contact variants are each seeded once per file through real
native checkout and payment. Restores retain their original clock and actor; each fault
then exercises its own real hold, claim, release or complimentary-cycle writer.
No reason rows or writer assertions were removed.

| Entry suffix (after staff[-transfer/-renewal]-release) | Retained guards |
| --- | --- |
| parity | Paid/unpaid readiness, cancellation, suspended service, permissions, and applicable financial, contact and input faults |

`ReleaseDecision` (Domains/Staff/Release) decides every release for the page offer and the
writer. `staff-release-decision-test.php` decides every mapped reason, every RenewalCode, null
and a future code for each request type, with Enom TEST purchases on and off, plus the paths a
fresh request cannot reach. `staff-release-reasons-test.php` reads the same facts from a real
held request for every code, then runs one code per path through guidance, notice, button
state and writer outcome, including byte-preserving refusals, the future-code page and zero
registrar writes. Permission, stale and revoked tickets stay in `staff-operation-actions-test.php`.

The state entrypoints retain complimentary renewal funding and fund permission, mixed
invoices, missing contacts and registry support, contact recovery, peer work, owner,
readmission limit, authority and transfer cutoff. They also exercise the retained
unsent call cap in both modes and missing, invalid or expired transfer inputs.
Each operation runs the faults that apply to it. `staff-transfer-release-inputs-test.php` keeps
only what the transfer state entrypoint cannot reach: a held transfer that genuinely awaits its
code, and a release offer that a page rereads when the deadline passes after an action.

All state entrypoints retain read-only offer SQL checks, financial facts/reason visibility,
actual Livewire action visibility/enabled state, actual writer outcomes and immutable refusals.
