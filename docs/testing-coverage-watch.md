# Watch storage and public suffix updates

`watch-discovery-lifecycle-test.php`, `watch-discovery-input-test.php`,
`watch-discovery-managed-test.php`, `watch-discovery-commit-test.php` and
`watch-discovery-notices-test.php` compose the local producer through the native scheduler.
They cover exact primary fields, suffix boundaries, shared sources, suspension, staff pause,
return after cancellation/deletion, mapping Off, ownership changes, managed and foreign
reservations, pending acquisition/adoption, atomic source rechecks and notice suppression.
Clocks, suffix downloads and DNS ports are synthetic. Installation gates stay off; no live
resolver, provider, notification channel or MariaDB race acceptance is implied.

`watch-storage-test.php` and `watch-dns-storage-test.php` verify encrypted audit, mapping and profile reasons, hidden serialization, retained plain/null rows and wrong-key refusal. The existing text columns hold encrypted reasons within the current 1000-character input limit; no schema migration is needed. Legacy plain rows remain readable and remain plain at rest until a separately authorized rewrite; append-only audit history is not rewritten. `watch-notice-delivery-test.php` verifies resend reasons remain in encrypted audit history without a duplicate delivery-row copy. `staff-watch-test.php` verifies an unavailable-key message while the rest of the watch page remains available.

`psl-staff-test.php` changes the source during a fake fetch, rejects the old completion, preserves the daily limit and completed results, and keeps same-source attempts valid. It also verifies a separately locked, uniquely queued public-data job on the existing default queue, weekly admission, and no PSL fetch from shared runtime work.
