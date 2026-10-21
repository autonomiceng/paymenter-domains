# Profile diagnostics and retained observation index

- `registration-namesilo-test.php` distinguishes explicit claim/evidence/binding
  mismatches from contact setup, acquisition state, funding, malformed bindings and
  infrastructure failures. Only explicit mismatches emit a false authority predicate.
- The offline OTE self-test covers read-only contact mismatch, authority drift,
  claim refusal, transport failure and non-success/malformed `contactList` status.
  It checks claim cleanup and absence of contact creation or domain purchase.
- `profile-diagnostics-test.php` checks redaction for every field mismatch category.
- `schema-test.php` verifies repair of the missing retained account/due index while
  other retained schema mismatches still refuse installation.
- The existing late-contact replay snapshot covers native holding and financial
  state; reminder alerts and deliveries remain intentionally outside that snapshot.

All scenarios use synthetic offline fixtures. They establish no provider acceptance
or shared-database concurrency guarantee.
