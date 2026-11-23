# Domains retention

Policy approved 2026-11-11. Money, tax and registrar-operation evidence is retained for at least seven years and never deleted automatically. The sweep has no financial-table deletion path.

The runtime examines at most 100 rows per family, resumes by primary key and wraps. Each source removal, encrypted cold copy and replay digest commits in one transaction.

Completed audit events and reminder receipts remain active for 13 calendar months, then move to encrypted cold history. Cold payloads expire three calendar years after the event. Event identities and replay digests survive cold expiry.

Unresolved sends, financial evidence, registrar operations and staff replay claims remain active.

New domain audit rows receive a separate UTC recording epoch; earlier rows with an explicit UTC zone also have a proven instant. Existing unmarked mixed-zone wall times are never reinterpreted. Only known ordinary events with a proven epoch and explicitly dated completed audit events can move. Unknown events, malformed dates, unreadable events and missing ownership stay active. Their identifiers and reasons are listed in `domains_retention_marks` (`reason IS NOT NULL`), ordered by `(family, source_id)` for bounded inspection. Neither that list nor scheduler logs contain payloads or contact details.

Customer deletion removes the owner's audit history, contact profile, retained contact properties and cold history within the native deletion transaction.

Paymenter cascades native invoices when deleting a user. This extension therefore refuses native deletion when invoices, holdings, routed services or registrar/profile-operation evidence exist. It cannot complete the requested invoice-bearing customer deletion with only retained name/email without a separate native financial-retention boundary. No invoice, payment, snapshot or uncertain operation is cascaded by this change. This is a remaining policy gap, not completed customer anonymization.

The additive migration verifies all retained tables and refuses partial or unrecorded history. Rollback refuses to drop history or replay protection.
