# Runtime health coverage

`runtime-health-test.php` acquires and releases real minute-budget leases for an
interactive request and a background request. Their shared usage appears under Read
capacity without changing the persisted budget during the health view.
This budget includes balance and catalogue reads as well as registrar changes;
identity verification and the
Enom TEST deployment-wide daily limit remain separate.
`native-identity-readiness-test.php` pins the empty minute-budget wording.
These are synthetic offline SQLite checks; no registrar traffic or shared-database
concurrency acceptance is implied.
