# Focused checks for adoption and registrars

Changes to these sources require the existing dependent tests even when their test
files are unchanged. Use the conservative union below with the ordinary `--only`
selector. Add new consumers when these dependencies change.

| Source trigger | Consumers to inspect |
| --- | --- |
| `Domains/Holding/Adoption/AdoptionReader.php` | `Support/adoption-preview.php` constructs the real reader. Follow its consumers through `adoption-confirm.php`, `adoption-holding.php`; retain `adoption-preview-test.php` and the static reason assertions in `staff-domains-test.php`. |
| `Domains/Admin/Resources/DomainResource/Pages/AdoptDomain.php` | Staff preview and confirmation flows, plus the shared adoption fixture consumers. |
| `Domains/Admin/Pages/RegistrarAccount.php` | Direct registrar page tests and transitive consumers of `Support/staff-tlds.php`, including route registration and settings actions. |
| `Domains/Presentation/AccountStatus.php` | Direct status assertions and `RegistrarFacts`/`AccountChecklist` consumers on registrar pages and installed scenarios. |
| `tests/Support/namesilo-enrollment-staff.php` | Native enrollment and installed enrollment/lifecycle callers. |

The 46 core tests include 24 adoption fixture consumers, 20 registrar page/shared
fixture consumers, direct account-status assertions and static reader-label assertions.
Some shared fixtures only register routes. Their inclusion does not establish preview
behavior. The original mid-read owner/role/property/price/site matrix is in
`tests/adoption-preview-test.php` mapping case; exception-injection checks in its guards case cover
a different seam and do not replace it.

All 13 installed tests build and verify the changed runtime archive members. Ten also
exercise affected page/helper branches. The fresh, upgrade and NameSilo package wrappers
provide archive-byte coverage here, without establishing those page branches. Seven
source-wide checks inspect runtime PHP, service callsites, fixture hostnames, package
members or tracked-file inventory. These groups total 66 unique registered tests.

With the pristine native dependency and pinned runtime configured, run:

```sh
python3 scripts/test.py --jobs 1 --only \
  tests/adopted-renewal-deadline-test.php \
  tests/adopted-renewal-history-test.php \
  tests/adopted-renewal-test.php tests/adopted-test-environment-test.php \
  tests/adoption-commit-test.php tests/adoption-confirm-test.php \
  tests/adoption-customer-test.php \
  tests/adoption-preview-test.php tests/adoption-renewal-intent-test.php \
  tests/contact-setup-test.php tests/domain-summary-managed-test.php \
  tests/enom-catalogue-test.php tests/enom-identity-test.php \
  tests/holding-adoption-test.php \
  tests/namesilo-enrollment-test.php tests/native-identity-readiness-test.php \
  tests/native-paid-baseline-test.php tests/native-server-settings-test.php \
  tests/registrar-balances-test.php \
  tests/registrars-page-test.php tests/reminder-form-test.php \
  tests/renewal-autopay-adopted-test.php tests/renewal-billing-settings-test.php \
  tests/staff-adopt-commit-test.php tests/staff-adoption-test.php \
  tests/staff-domains-test.php tests/staff-navigation-test.php \
  tests/staff-settings-history-test.php tests/staff-tld-actions-test.php \
  tests/staff-tld-grants-test.php tests/staff-tld-page-test.php \
  tests/staff-tld-reads-test.php tests/staff-tld-writes-test.php \
  tests/status-sets-test.php \
  tests/api-test.php \
  tests/fixture-policy-test.php tests/owner-declutter-test.php \
  tests/php83-compatibility-test.php tests/schema-identifiers-test.php \
  tests/test_package.py tests/test_runner.py \
  tests/test_installed_package.py tests/test_installed_package_fresh.py \
  tests/test_installed_namesilo_enrollment.py tests/test_installed_package_acquisition.py \
  tests/test_installed_package_enom_exchange.py tests/test_installed_package_enom_test.py \
  tests/test_installed_package_enom_live.py tests/test_installed_package_enom_renewal.py \
  tests/test_installed_package_enom_management.py tests/test_installed_package_enom_transfer.py \
  tests/test_installed_package_namesilo.py
```

The runner validates every path against the test manifest, rejects duplicate or
missing suite registrations, and records the exact selected and omitted tests. Keep
this list unique and compare it with the direct references and transitive fixture
consumers when the trigger files change. This command is focused evidence, not a full
boundary run. Installed tests require the same serialized resources as a full suite;
when splitting the command, keep all 58 regular checks and all 13 installed checks.
