# Offline tests

Run `scripts/test.py` after [bootstrap](../docs/testing.md). `suites.json` explicitly registers
all executable tests; missing, duplicate and unregistered paths fail. `bootstrap.php` supplies
the standalone autoloader and assertions. PHP suites use pinned PHP and disabled network/process
entry points; Python checks use the standard library. Receipts identify omitted/unimplemented suites.

Synthetic fixtures use example domains, documentation IPs and placeholder credentials. The provider
and cURL suites preserve read-only dispatch, fallback/unknown classification, exact lexical money,
bounds and redaction. Purchase mappings cannot be dispatched. No real provider call is made.

Native checks require an explicitly selected pristine Paymenter dependency. `native-app.php` boots
only the disposable copy; `native-fixture-test.php` proves the harness's migration/model/rollback and
HTTP guards. The failure-exit regression is skipped explicitly when native is omitted. No Domains
extension, billing or ownership workflow is installed or tested yet. Package tests use synthetic
entry points and assert the real foundation is not releasable. UI coverage remains unimplemented.
